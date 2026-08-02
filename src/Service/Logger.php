<?php
declare(strict_types=1);

namespace Ovos\Service;

use Ovos\Client;
use Ovos\Logger\Normalizer;
use Ovos\Logger\Traits\TraitFile;
use Ovos\Logger\Writer;
use Ovos\Service;
use Throwable;

use function array_merge;
use function date;
use function get_class;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function json_encode;
use function ltrim;
use function max;
use function mb_substr;
use function method_exists;
use function preg_match;
use function preg_replace;
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

/**
 * Logger
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Logger extends Service implements Writer
{
	use TraitFile;
	
	public const string SYMBOL = 'logger';
	
	/**
	 * An e-mail address inside a string value. The first local-part character
	 * is kept and the domain is left intact (j***@example.com) — enough to
	 * tell providers/customers apart while dropping the identifying part.
	 */
	protected const string EMAIL_PATTERN = '~([a-z0-9._%+\-])[a-z0-9._%+\-]*@([a-z0-9.\-]+\.[a-z]{2,})~i';
	
	protected string $dir = 'events';
	
	protected string $file = 'events';
	
	/**
	 * Field names whose value is dropped entirely ([redacted]). Matches the
	 * console's documented canonical set (docs/SENDER.md). Case-insensitive;
	 * extend per project with addRemove().
	 */
	protected array $remove = [
		'~pass(word|wd)?~i',
		'~pwd~i',
		'~token~i',
		'~secret~i',
		'~authorization~i',
		'~cookie~i',
		'~api[_-]?key~i',
	];
	
	/**
	 * A PATH segment long enough to be worth judging. Single-use credentials
	 * travel in paths and are followed over GET — /reset-password/<jwt>,
	 * /invite/<token>, a magic link — and a query-parameter rule never sees
	 * them.
	 *
	 * Loose on purpose: the DECISION is looksSecret(). A length-only rule (any
	 * 24+ character segment) is also what a readable slug looks like, and it
	 * turned /de/pre-und-onboarding/ into /de/[redacted]/ wherever it shipped.
	 */
	protected const string PATH_CANDIDATE = '~(/)([A-Za-z0-9_.-]{20,})(?=[/?#]|$)~';
	
	/**
	 * Names that are credentials ONLY as query parameters — too generic to drop
	 * as field names, where `key` is a cache key half the time.
	 *
	 * `key` is the one that matters: single-use tokens travel as ?key=
	 * (WordPress's password reset is wp-login.php?action=rp&key=<20 chars>, and
	 * plenty of unsubscribe and invite links use the same name), and none of the
	 * patterns above touch it — api[_-]?key needs the api. Extend per project
	 * with addQueryNames().
	 */
	protected array $queryNames = [
		'key',
		'auth',
		'code',
		'sig',
		'signature',
	];
	
	/**
	 * Field names whose value is masked to all-but-the-first character
	 * (e.g. "marcin" -> "m***"). Anchored so identifier fields like userId
	 * or userAgent are left intact; extend per project with addUsernames()
	 * (a custom login field like "nick"). E-mails are handled separately,
	 * by value.
	 */
	protected array $usernames = [
		'~^user([_-]?(name|login))?$~i',
		'~^login$~i',
	];
	
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
	
	public function addUsernames(
		array $usernames,
	): static
	{
		$this->usernames = array_merge($this->usernames, $usernames);
		
		return $this;
	}
	
	/**
	 * Extra query-parameter names to drop (a project's own single-use token
	 * parameter) — see $queryNames.
	 */
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
	
	public function getUsernames(): array
	{
		return $this->usernames;
	}
	
	/**
	 * Logs an event (message/error/exception) to the file. Direct callers get
	 * file-only logging; forwarding to the error console lives in the Events
	 * fan-out (Events::log delivers to this writer and the console Writer).
	 */
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
		$this->output($output);
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
				// the neighbours were guarded already; this one was why a log
				// line about an error could raise a second error
				$_SERVER['REQUEST_METHOD'] ?? '',
				$_SERVER['REQUEST_URI'] ?? '',
				Client::getIp(),
				$_SERVER['HTTP_USER_AGENT'] ?? ''
			);
		}
		else if($this->app->isInterfaceCli())
		{
			$prepend.= 'CLI: ' . (isset($_SERVER['argv']) ?
				implode(' ', $_SERVER['argv']) : 'no arguments');
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
	 * Scrubs request-style data: secret fields are dropped, username fields
	 * and e-mail addresses (in any field) are anonymized. The console scrubs
	 * again server-side as a backstop.
	 */
	public function remove(
		array $data,
	): array
	{
		foreach($data as $key => $value)
		{
			// secret fields are dropped wholesale — before recursing, so a
			// secret key whose value is an array cannot leak through its children
			if(is_string($key) && $this->matchesAny($this->remove, $key))
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
			
			// an e-mail in ANY field (a login that is an e-mail, a "to"
			// address, …) — masked with the domain kept
			$masked = $this->maskEmails($value);
			if($masked !== $value)
			{
				$data[$key] = $masked;
				
				continue;
			}
			
			if(is_string($key) && $this->matchesAny($this->usernames, $key))
			{
				$data[$key] = $this->maskName($value);
			}
		}
		
		return $data;
	}
	
	/**
	 * Scrubs a URL the way remove() scrubs request arrays: secret-named
	 * query parameters are dropped, e-mail values (in any parameter) are
	 * masked with the domain kept and username-named parameters are
	 * anonymized. The same data already leaves through request.get — this
	 * closes the uri/referer copy of it. Untouched parameters stay
	 * byte-for-byte identical; values are only re-encoded when changed.
	 */
	public function removeFromUrl(
		string $url,
	): string
	{
		// the PATH first: a token there is not a query parameter, and nothing
		// else in this class would look at it
		$position = strpos($url, '?');
		$url = $this->removeFromPath(
			$position === false ? $url : substr($url, 0, $position),
		) . ($position === false ? '' : substr($url, $position));
		
		$position = strpos($url, '?');
		if($position !== false)
		{
			$query = (string)preg_replace_callback(
				'~(^|&)([^&=]+)=([^&]*)~',
				function(array $match): string
				{
					$name = rawurldecode($match[2]);
					if($this->matchesAny($this->remove, $name)
						|| in_array(strtolower(trim($name)), $this->queryNames, true))
					{
						return $match[1] . $match[2] . '=[redacted]';
					}
					
					$value = rawurldecode($match[3]);
					$masked = $this->maskEmails($value);
					if($masked === $value
						&& $this->matchesAny($this->usernames, rawurldecode($match[2])))
					{
						$masked = $this->maskName($value);
					}
					
					if($masked === $value)
					{
						return $match[0];
					}
					
					// keep the mask readable — @ and * are legal in a query
					return $match[1] . $match[2] . '='
						. strtr(rawurlencode($masked), ['%40' => '@', '%2A' => '*']);
				},
				substr($url, $position + 1),
			);
			
			$url = substr($url, 0, $position + 1) . $query;
		}
		
		// a plain e-mail in the path (unsubscribe links and the like)
		return $this->maskEmails($url);
	}
	
	/**
	 * Token-shaped PATH segments -> [redacted], leaving readable slugs alone.
	 */
	public function removeFromPath(
		string $path,
	): string
	{
		return (string)preg_replace_callback(
			self::PATH_CANDIDATE,
			fn(array $match): string => $this->looksSecret($match[2])
				? $match[1] . '[redacted]'
				: $match[0],
			$path,
		);
	}
	
	/**
	 * A path segment ending in one of these is a static asset, not a
	 * credential — see looksSecret().
	 *
	 * Build output and media only. `pdf`, `zip`, `csv`, `xlsx`, `json`, `xml`
	 * and friends are deliberately absent: a signed one-time download link ends
	 * in one of those, and none of them is ever emitted by a bundler.
	 */
	protected const string ASSET_PATTERN = '~\.(?:js|mjs|cjs|jsx|ts|tsx|css|scss|less|map|wasm'
		. '|woff2?|ttf|otf|eot'
		. '|svg|png|jpe?g|gif|webp|avif|ico|bmp'
		. '|mp3|mp4|webm|ogg|wav)$~i';
	
	/**
	 * A path segment is a secret, not a slug, when it has no word structure and
	 * carries the character mix a generated token does: a JWT, a uuid, a long
	 * hex string, or one long run of mixed case with digits. A slug is words
	 * joined by - or _, lower case, at most the odd year.
	 *
	 * Where it is genuinely ambiguous this errs towards redaction: an
	 * unreadable URI costs less than a leaked reset token. The rule is shared
	 * with ovos/console's own Scrubber and its browser and node clients — none
	 * of which can share code with this — so keep them equal; the console repo
	 * carries the corpus that pins all of them.
	 *
	 * A cache-busted STATIC ASSET is the exception, and not an ambiguous one.
	 * Every bundler names its output after a content hash — main.<md5>.js,
	 * index-DkL9mQxZ8vB2nR4tY7wA.js, <group>_<md5>.<mtime>.js — and each trips
	 * a rule below on its 32-character or mixed-case run. Nothing was protected
	 * by redacting them: the file was fetched over a plain, uncredentialed
	 * request. It cost `file`, the field a JS error is read from.
	 */
	public function looksSecret(
		string $segment,
	): bool
	{
		// before the asset rule: a uuid and a bare hex run hold no dot, so only
		// a JWT could end in something that reads like an extension, and a JWT
		// stays a JWT
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
	 * Scrubs CLI argv the way remove() scrubs request arrays. Both argument
	 * styles are covered: --password=x / password=x get the value dropped,
	 * and a bare secret-named token drops the FOLLOWING argument (the
	 * framework CLI passes "name value" pairs). E-mails in any argument are
	 * masked with the domain kept.
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
				$args[$key] = $this->matchesAny($this->remove, $match[2])
					? $match[1] . $match[2] . '=[redacted]'
					: $this->maskEmails($arg);
				
				continue;
			}
			
			if($this->matchesAny($this->remove, ltrim($arg, '-')))
			{
				// the name stays, the value that follows is dropped
				$removeNext = true;
				
				continue;
			}
			
			$args[$key] = $this->maskEmails($arg);
		}
		
		return $args;
	}
	
	/**
	 * Whether $key matches any of the given key patterns
	 *
	 * @param string[] $patterns
	 */
	protected function matchesAny(
		array $patterns,
		string $key,
	): bool
	{
		foreach($patterns as $pattern)
		{
			if(preg_match($pattern, $key) === 1)
			{
				return true;
			}
		}
		
		return false;
	}
	
	/**
	 * Masks every e-mail address in a string, keeping the domain
	 * (john.doe@example.com -> j***@example.com). Returns the value unchanged
	 * when it holds no e-mail.
	 */
	protected function maskEmails(
		string $value,
	): string
	{
		return (string)preg_replace(self::EMAIL_PATTERN, '${1}***@${2}', $value);
	}
	
	/**
	 * Keeps the first character and masks the rest (marcin -> m***); an empty
	 * string stays empty. The fixed suffix does not leak the original length.
	 */
	protected function maskName(
		string $value,
	): string
	{
		if($value === '')
		{
			return $value;
		}
		
		return mb_substr($value, 0, 1) . '***';
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
