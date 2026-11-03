<?php
declare(strict_types=1);

namespace Ovos\Service;

use Ovos\Application;
use Ovos\ArrayObject;
use Ovos\Client;
use Ovos\Container\ArrayObject as InjectArrayObject;
use Ovos\Container\Inject;
use Ovos\Logger\Normalizer;
use Ovos\Logger\Traits\TraitFile;
use Ovos\Logger\Writer;
use Ovos\Service;
use Throwable;

use function array_merge;
use function count;
use function date;
use function explode;
use function get_class;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function json_encode;
use function ltrim;
use function max;
use function mb_strlen;
use function mb_substr;
use function method_exists;
use function min;
use function preg_match;
use function preg_replace_callback;
use function preg_split;
use function rawurldecode;
use function rawurlencode;
use function sprintf;
use function strlen;
use function strpos;
use function strtolower;
use function strtr;
use function substr;
use function trim;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const PHP_EOL;

/**
 * Logger
 *
 * Writes events to a daily file and redacts what an event carries: secrets
 * always, identities (e-mail addresses, username fields) by policy. The
 * logger.identities switch sets the policy for this logger: keep (the
 * default) or mask. A destination that handles identities itself takes a
 * keepingIdentities() copy.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Logger extends Service implements Writer
{
	use TraitFile;
	
	public const string SYMBOL = 'logger';
	
	/** logger.identities value that masks e-mail addresses and username fields. */
	public const string IDENTITIES_MASK = 'mask';
	
	/** logger.identities value that leaves identities as they are (the default). */
	public const string IDENTITIES_KEEP = 'keep';
	
	/**
	 * maskName() keeps every MASK_GROUP-th character and stars the rest, so a
	 * mask is as long as the value it replaced. Past MASK_MAX characters the
	 * stars stop and the real length is stated instead ("[200]").
	 */
	public const int MASK_GROUP = 4;
	
	public const int MASK_MAX = 24;
	
	/** A cut maskName() result, which a second pass must leave as it is. */
	public const string MASKED_CUT_PATTERN = '~^(?:.\*{3})+\[\d+\]$~u';
	
	/** An e-mail address inside a string; the local part is masked, the domain kept. */
	protected const string EMAIL_PATTERN = '~([a-z0-9._%+\-]+)@([a-z0-9.\-]+\.[a-z]{2,})~i';
	
	/** An address already masked: a star or a cut length before the @, which no real local part holds. */
	protected const string MASKED_EMAIL_PATTERN = '~[*\]][a-z0-9._%+\-]*@[a-z0-9.\-]+\.[a-z]{2,}~i';
	
	/** A path segment long enough for looksSecret() to judge. */
	protected const string PATH_CANDIDATE = '~(/)([A-Za-z0-9_.-]{20,})(?=[/?#]|$)~';
	
	/**
	 * A segment ending in one of these is a static asset, not a credential.
	 * Build output and media only: a signed download link ends in pdf, zip,
	 * csv or json, and no bundler emits those.
	 */
	protected const string ASSET_PATTERN = '~\.(?:js|mjs|cjs|jsx|ts|tsx|css|scss|less|map|wasm'
		. '|woff2?|ttf|otf|eot'
		. '|svg|png|jpe?g|gif|webp|avif|ico|bmp'
		. '|mp3|mp4|webm|ogg|wav)$~i';
	
	protected string $dir = 'events';
	
	protected string $file = 'events';
	
	#[Inject('config')]
	#[InjectArrayObject('logger')]
	protected ?ArrayObject $config = null;
	
	/** Field-name patterns whose values are replaced with [redacted]. */
	protected array $remove = [
		'~pass(word|wd)?~i',
		'~pwd~i',
		'~token~i',
		'~secret~i',
		'~authorization~i',
		'~cookie~i',
		'~api[_-]?key~i',
		'~jwt~i',
		'~bearer~i',
		'~signature~i',
		'~credential~i',
		'~(private|access|consumer|licen[cs]e|encryption|signing)[_-]?key~i',
		'~backup[_-]?codes?~i',
		'~auth[_-]?code~i',
		'~csrf|xsrf~i',
		'~sess(ion)?[_-]?id~i',
		// a one-time code and a bare `auth` only as a word of the name:
		// otpaper and author stay readable
		'~(?<![a-z0-9])(otp|totp|mfa|2fa)([_-]?codes?)?(?![a-z0-9])~i',
		'~(?<![a-z])auth(?![a-z])~i',
	];
	
	/**
	 * Names that are credentials only as URL query parameters: a reset key
	 * (?key=), an OAuth code, a signed URL's sig. As field names they are too
	 * generic (a cache key, a country code), so remove() leaves them alone.
	 */
	protected array $queryNames = [
		'key',
		'auth',
		'code',
		'sig',
		'signature',
		'otp',
		'pin',
		'hash',
	];
	
	/** Field-name patterns whose values are masked when identities are masked. */
	protected array $usernames = [
		'~^user([_-]?(name|login))?$~i',
		'~^login$~i',
	];
	
	/** Whether identities are masked; null follows logger.identities. */
	protected ?bool $maskIdentities = null;
	
	/** On a keepingIdentities() copy: the username fields left raw, null for every one. */
	protected ?array $keptUsernames = null;
	
	public function boot(Application $app): void
	{
		parent::boot($app);
		$app->getServices()->events->addWriter($this, static::SYMBOL);
	}
	
	/**
	 * A copy for a destination that masks and stores identities itself: secrets
	 * are dropped as always, e-mail addresses stay raw, and so do the username
	 * fields matching $usernames (every one when null). A username field the
	 * destination does not know stays masked, since it would keep it in clear.
	 */
	public function keepingIdentities(
		?array $usernames = null,
	): static
	{
		$copy = clone $this;
		$copy->maskIdentities = false;
		$copy->keptUsernames = $usernames;
		
		return $copy;
	}
	
	/** A copy that masks identities whatever logger.identities says. */
	public function maskingIdentities(): static
	{
		$copy = clone $this;
		$copy->maskIdentities = true;
		$copy->keptUsernames = null;
		
		return $copy;
	}
	
	public function masksIdentities(): bool
	{
		return $this->maskIdentities
			?? $this->config?->identities === self::IDENTITIES_MASK;
	}
	
	public function addRemove(
		array $remove,
	): static
	{
		$this->remove = array_merge($this->remove, $remove);
		
		return $this;
	}
	
	public function getRemove(): array
	{
		return $this->remove;
	}
	
	/** Extra query parameter names to redact, such as an application's own token parameter. */
	public function addQueryNames(
		array $names,
	): static
	{
		$this->queryNames = array_merge($this->queryNames, $names);
		
		return $this;
	}
	
	public function getQueryNames(): array
	{
		return $this->queryNames;
	}
	
	/** Extra username field patterns, such as an application's own login field. */
	public function addUsernames(
		array $usernames,
	): static
	{
		$this->usernames = array_merge($this->usernames, $usernames);
		
		return $this;
	}
	
	public function getUsernames(): array
	{
		return $this->usernames;
	}
	
	public function log(
		...$event,
	): static
	{
		$normalized = Normalizer::normalize($event);
		if($normalized === null)
		{
			return $this;
		}
		
		[$throwable, $extra] = $normalized;
		$this->write($throwable, $extra);
		
		return $this;
	}
	
	/**
	 * Writer: append the formatted event — with the request/CLI context and
	 * redacted request variables — to today's log file.
	 */
	public function write(
		Throwable $event,
		array $extra,
	): void
	{
		$output = $this->getEvent($event);
		
		// extras
		foreach($extra as $key => $value)
		{
			$output.= $key . ': ' . $value . PHP_EOL;
		}
		// prepend
		$prepend = $this->getPrepend();
		// append
		$append = $this->getAppend();
		
		$output = $prepend . $output . $append . PHP_EOL;
		$this->output($this->removeText($output));
	}
	
	public function getPrepend(): string
	{
		// prepend
		$prepend = date('c ');
		if($this->app->isInterfaceHttp())
		{
			$prepend.= sprintf
			(
				"%s: %s\n%s %s",
				$_SERVER['REQUEST_METHOD'] ?? '',
				$this->removeFromUrl($_SERVER['REQUEST_URI'] ?? ''),
				Client::getIp(),
				$_SERVER['HTTP_USER_AGENT'] ?? ''
			);
		}
		else if($this->app->isInterfaceCli())
		{
			$prepend.= 'CLI: ' . (isset($_SERVER['argv']) ?
				implode(' ', $this->removeFromArgs($_SERVER['argv'])) : 'no arguments');
		}
		
		return $prepend . PHP_EOL;
	}
	
	public function getAppend(): string
	{
		// append
		$append = '';
		if(!empty($_GET))
		{
			$append.= 'GET: ' . PHP_EOL
				. json_encode($this->remove($_GET),
					JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT,
				) . PHP_EOL;
		}
		if(!empty($_POST))
		{
			$append.= 'POST: ' . PHP_EOL
				. json_encode($this->remove($_POST),
					JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT,
				) . PHP_EOL;
		}
		if(!empty($_FILES))
		{
			$append.= 'FILES: ' . PHP_EOL
				. json_encode($_FILES,
					JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT,
				) . PHP_EOL;
		}
		
		return $append;
	}
	
	/**
	 * Request-style data: secret fields are dropped, and under the masking
	 * policy e-mail addresses (in any field) and username fields are masked.
	 */
	public function remove(
		array $data,
	): array
	{
		foreach($data as $key => $value)
		{
			// before recursing, so a secret holding an array goes whole
			if(is_string($key) && $this->isSecretName($key))
			{
				$data[$key] = '[redacted]';
				
				continue;
			}
			
			if(is_array($value))
			{
				$data[$key] = $this->remove($value);
				
				continue;
			}
			
			if(is_string($value) === false)
			{
				continue;
			}
			
			// an address in any field belongs to the e-mail rule, masked or
			// not, so the username mask below never drops its domain
			$masked = self::maskEmails($value);
			if($masked !== $value
				|| preg_match(self::EMAIL_PATTERN, $value) === 1
				|| preg_match(self::MASKED_EMAIL_PATTERN, $value) === 1)
			{
				if($this->masksIdentities())
				{
					$data[$key] = $masked;
				}
				
				continue;
			}
			
			if(is_string($key) && $this->masksUsername($key))
			{
				$data[$key] = self::maskName($value);
			}
		}
		
		return $data;
	}
	
	/**
	 * A URL as remove() treats request data: token-shaped path segments and
	 * secret or query-only parameter names are redacted, identities masked by
	 * policy. Untouched parameters stay byte for byte as they were.
	 */
	public function removeFromUrl(
		string $url,
	): string
	{
		$position = strpos($url, '?');
		if($position === false)
		{
			$url = $this->removeFromPath($url);
			
			return $this->masksIdentities() ? self::maskEmails($url) : $url;
		}
		
		$query = (string)preg_replace_callback(
			'~(^|&)([^&=]+)=([^&#]*)~',
			function(array $match): string
			{
				$name = rawurldecode($match[2]);
				if($this->isSecretName($name)
					|| in_array(strtolower(trim($name)), $this->queryNames, true))
				{
					return $match[1] . $match[2] . '=[redacted]';
				}
				
				$value = rawurldecode($match[3]);
				$masked = $this->masksIdentities() ? self::maskEmails($value) : $value;
				if($masked === $value
					&& preg_match(self::EMAIL_PATTERN, $value) !== 1
					&& preg_match(self::MASKED_EMAIL_PATTERN, $value) !== 1
					&& $this->masksUsername($name))
				{
					$masked = self::maskName($value);
				}
				
				if($masked === $value)
				{
					return $match[0];
				}
				
				// @, * and the brackets of a cut length stay readable
				return $match[1] . $match[2] . '='
					. strtr(rawurlencode($masked),
						['%40' => '@', '%2A' => '*', '%5B' => '[', '%5D' => ']']);
			},
			substr($url, $position + 1),
		);
		
		$path = $this->removeFromPath(substr($url, 0, $position));
		if($this->masksIdentities())
		{
			// an address in the path, such as an unsubscribe link
			$path = self::maskEmails($path);
		}
		
		return $path . '?' . $query;
	}
	
	/**
	 * Path segments: the value after a secret name (/token/value) and every
	 * token-shaped segment (looksSecret()) become [redacted]; slugs stay.
	 */
	public function removeFromPath(
		string $path,
	): string
	{
		$segments = explode('/', $path);
		$last = count($segments) - 1;
		for($index = 0; $index < $last; $index++)
		{
			if($segments[$index] !== ''
				&& $segments[$index + 1] !== ''
				&& $this->isSecretName(rawurldecode($segments[$index])))
			{
				$segments[++$index] = '[redacted]';
			}
		}
		
		return (string)preg_replace_callback(
			self::PATH_CANDIDATE,
			fn(array $match): string => $this->looksSecret($match[2])
				? $match[1] . '[redacted]'
				: $match[0],
			implode('/', $segments),
		);
	}
	
	/**
	 * Whether a path segment is a generated token rather than a slug: a JWT, a
	 * uuid, a long hex run, or one long mixed-case run with digits. A slug is
	 * words joined by - or _. A cache-busted asset (app.<hash>.js) is kept:
	 * it was fetched without credentials. Where it is ambiguous this redacts,
	 * since an unreadable URL costs less than a leaked reset token.
	 */
	public function looksSecret(
		string $segment,
	): bool
	{
		// before the asset rule: only a JWT could end in something that reads
		// like an extension, and it stays a JWT
		if(preg_match('~^eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$~', $segment) === 1
			|| preg_match('~^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$~i', $segment) === 1
			|| preg_match('~^[0-9a-f]{24,}$~i', $segment) === 1)
		{
			return true;
		}
		
		if(preg_match(self::ASSET_PATTERN, $segment) === 1)
		{
			return false;
		}
		
		if(strlen($segment) < 24)
		{
			return false;
		}
		
		$longest = 0;
		foreach(preg_split('~[-_.]+~', $segment) ?: [] as $run)
		{
			$longest = max($longest, strlen($run));
		}
		
		$digits = preg_match('~[0-9]~', $segment) === 1;
		
		// one long mixed-case run with digits, or a very long single run
		return ($digits && preg_match('~[A-Z]~', $segment) === 1 && $longest >= 16)
			|| ($digits && $longest >= 32);
	}
	
	/**
	 * CLI arguments: --password=x and password=x lose the value, a bare
	 * secret name drops the argument after it, and e-mail addresses are
	 * masked by policy.
	 */
	public function removeFromArgs(
		array $args,
	): array
	{
		$removeNext = false;
		
		foreach($args as $key => $arg)
		{
			if(is_string($arg) === false)
			{
				continue;
			}
			
			if($removeNext)
			{
				$args[$key] = '[redacted]';
				$removeNext = false;
				
				continue;
			}
			
			if(preg_match('~^(--?)?([^=]+)=(.*)$~s', $arg, $match) === 1)
			{
				$args[$key] = $this->isSecretName($match[2])
					? $match[1] . $match[2] . '=[redacted]'
					: $this->maskIdentitiesIn($arg);
				
				continue;
			}
			
			if($this->isSecretName(ltrim($arg, '-')))
			{
				// the name stays, the value that follows is dropped
				$removeNext = true;
				
				continue;
			}
			
			$args[$key] = $this->maskIdentitiesIn($arg);
		}
		
		return $args;
	}
	
	public function isSecretName(
		string $name,
	): bool
	{
		return $this->matchesAny($this->remove, $name);
	}
	
	/**
	 * Text such as a raw body or a log line: the value of every secret-named
	 * pair is redacted, and under the masking policy username pairs and
	 * e-mail addresses are masked.
	 */
	public function removeText(
		string $text,
	): string
	{
		if($text === '')
		{
			return $text;
		}
		
		$name = '(["\']?)([A-Za-z0-9_.\-\[\]]+)\1';
		// A colon in PHP's :: operator is not a key/value separator.
		$separator = '(\s*(?:=>|:(?!:)|=)\s*)';
		$scheme ='(?:(?:bearer|basic|token|digest)\s+)?';
		
		// Quoted values.
		$text = $this->removePairs($text,
			'~' . $name . $separator . '(["\'])' . $scheme
				. '((?:\\\\.|(?!\4)[^\\\\])*)\4~i');
		
		// Escaped quotes inside a JSON string.
		$text = $this->removePairs($text,
			'~' . $name . $separator . '(\\\\["\'])' . $scheme
				. '((?:(?!\4).)*)\4~i');
		
		// Unquoted values.
		$text = $this->removePairs($text,
			'~' . $name . $separator . '()' . $scheme
				. '([^"\'\s,;)&}>\\\\]{1,512})~i');
		
		// XML element values.
		$text = (string)preg_replace_callback(
			'~<([A-Za-z0-9_.:\-]+)((?:\s[^>]*)?)>([^<]*)</\1\s*>~i',
			fn(array $match): string => $this->isSecretName($match[1])
				? '<' . $match[1] . $match[2] . '>[redacted]</' . $match[1] . '>'
				: $match[0],
			$text,
		);
		
		if($this->masksIdentities() === false)
		{
			return $text;
		}
		
		// a username pair; `@` ends the value, so an address is left to
		// maskEmails(), which keeps its domain
		$text = (string)preg_replace_callback(
			'~' . $name . $separator . '(["\']?)([^"\'\s,;)&}>@]{1,256})\4~i',
			fn(array $match): string => $match[5] === '[redacted]'
				|| $this->matchesAny($this->usernames, $match[2]) === false
				? $match[0]
				: $match[1] . $match[2] . $match[1]
					. $match[3] . $match[4] . self::maskName($match[5]) . $match[4],
			$text,
		);
		
		return self::maskEmails($text);
	}
	
	/**
	 * Every e-mail address in a string with its local part masked and its
	 * domain kept (john.doe@example.com -> j***.***@example.com).
	 */
	public static function maskEmails(
		string $value,
	): string
	{
		return (string)preg_replace_callback(
			self::EMAIL_PATTERN,
			static fn(array $match): string
				=> self::maskName($match[1]) . '@' . $match[2],
			$value,
		);
	}
	
	/**
	 * Keeps every MASK_GROUP-th character and stars the rest (bob -> b**,
	 * marcin -> m***i*); past MASK_MAX characters the length is stated
	 * instead. Masking a mask changes nothing.
	 */
	public static function maskName(
		string $value,
	): string
	{
		if($value === ''
			|| preg_match(self::MASKED_CUT_PATTERN, $value) === 1)
		{
			return $value;
		}
		
		$length = mb_strlen($value);
		$cut = min($length, self::MASK_MAX);
		$masked = '';
		for($index = 0; $index < $cut; $index++)
		{
			$masked.= $index % self::MASK_GROUP === 0
				? mb_substr($value, $index, 1)
				: '*';
		}
		
		return $length > $cut ? $masked . '[' . $length . ']' : $masked;
	}
	
	protected function removePairs(
		string $text,
		string $pattern,
	): string
	{
		return (string)preg_replace_callback(
			$pattern,
			fn(array $match): string => $match[5] === '' || $this->isSecretName($match[2]) === false
				? $match[0]
				: $match[1] . $match[2] . $match[1]
					. $match[3] . $match[4] . '[redacted]' . $match[4],
			$text,
		);
	}
	
	/**
	 * Whether a value under this field name is masked as a username: always
	 * under the masking policy; on a keepingIdentities() copy only when the
	 * name is not one of the kept username fields.
	 */
	protected function masksUsername(
		string $key,
	): bool
	{
		if($this->matchesAny($this->usernames, $key) === false)
		{
			return false;
		}
		
		if($this->masksIdentities())
		{
			return true;
		}
		
		return $this->keptUsernames !== null
			&& $this->matchesAny($this->keptUsernames, $key) === false;
	}
	
	protected function maskIdentitiesIn(
		string $value,
	): string
	{
		return $this->masksIdentities() ? self::maskEmails($value) : $value;
	}
	
	protected function matchesAny(
		array $patterns,
		string $name,
	): bool
	{
		foreach($patterns as $pattern)
		{
			if(preg_match($pattern, $name) === 1)
			{
				return true;
			}
		}
		
		return false;
	}
	
	public function getEvent(
		object $event,
	): string
	{
		$output = '';
		
		if($event instanceof Throwable
			&& method_exists($event, 'getPrevious'))
		{
			$previous = false;
			do
			{
				$className = get_class($event);
				if($previous)
				{
					$className = "\nPrevious " . $className;
				}
				$output.= $event->getFile() . ':' . $event->getLine() . PHP_EOL;
				$output.= $className . ': ' . $event->getMessage()
					. PHP_EOL . $event->getTraceAsString() . PHP_EOL;
				
				$previous = true;
			}
			while($event = $event->getPrevious());
		}
		
		return $output;
	}
}
