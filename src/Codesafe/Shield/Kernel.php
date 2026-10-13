<?php
declare(strict_types=1);

/**
 * ovos/codesafe Shield kernel — zero dependencies, vendor this file.
 *
 *   require_once __DIR__ . '/Shield.php';
 *   use Ovos\Codesafe\Shield\{Kernel, Store, Facts, Consent, Verdict};
 *
 *   $kernel = new Kernel(
 *       'https://codesafe.example',                 // codesafe instance base URL
 *       $apiKey,                                   // the project api_key the sender already holds (secret!)
 *       new Store('/var/cache/app/shield.json', 'myapp:shield'),   // file tier + APCu prefix
 *       report: fn(string $kind, string $message, array $extra) => $sender->reportRefusal($kind, $message, $extra),
 *   );
 *   $consent = new Consent(detect: $config['shield_detect'], enforce: $config['shield_enforce'], kill: $config['shield_kill']);
 *   $verdict = $kernel->handle(Facts::fromServer($_SERVER, $_POST, fn() => file_get_contents('php://input')), $consent);
 *   if($verdict->isBlock())
 *   {
 *       // the adapter's own 403 — the kernel never emits a response
 *   }
 *   // on the host's own tick (the sender's minute flush, a shutdown hook, cron):
 *   $kernel->pull($consent);
 *
 * The kernel knows three things — Facts (what the adapter says about the
 * request; the kernel never reads a superglobal), Consent (three switches
 * read from the host's OWN config on every request: detect = observe,
 * enforce = block proven rules, kill = nothing and no network) and the Store
 * (rules and counters, two tiers: APCu for speed, a JSON file for
 * durability — a cleared APCu re-primes from the file, a host without APCu
 * runs on the file alone). It does three things: pull() — a conditional GET
 * of the project's live rules under the store's lock, on the host's tick,
 * every INTERVAL seconds, a 304 costs nothing, a failure leaves the cache
 * alone; judge() — pure over the cached ruleset, returns pass | observe |
 * block and never a response; report() — hands the match to a callable the
 * adapter supplied (the host's sender) as a `shield_observe` /
 * `shield_block` security event, `matched` capped at 200 bytes.
 *
 * Fails open on everything: a missing store, an unknown contract, a rule
 * the engine cannot run, a thrown anything — the request passes. A missing
 * store also forces the very next pull. A ruleset older than CEILING (24 h,
 * a codesafe instance gone silent) lifts by itself; a successful pull — 200 or 304 —
 * renews it. The verdict is `block` only when the rule is `proven` AND
 * consent.enforce is on; an `observe` rule never blocks whatever the
 * switches say — the per-rule safety lives here.
 *
 * Rules are codesafe's one grammar for every runtime (dialect re2-2026,
 * contract 1): {field: uri|ua|ip|body, op: equals|prefix|contains|cidr|regex,
 * value, ci}. Literal ops fold ASCII only; regex runs as `~…~D[i]`, never
 * `u` — the two semantics codesafe's conformance corpus pins.
 *
 * Two kinds. A match rule (no `kind` key) decides on its own: observe or a
 * 403. A rate rule (`kind: rate`, `rate: {key: ip|user|route, limit,
 * window}`) is the same predicate plus a counter — over `limit` requests per
 * `window` seconds for one address, one user or the route itself, it
 * observes or answers 429 with Retry-After. The match rules are judged
 * first; a rate rule counts only a request no match rule decided. Counting
 * needs APCu (the file tier cannot count): without it rate rules are
 * skipped, and the limit is per POOL — one server's APCu, not the site's.
 * The pull names `X-Shield-Kinds: match,rate,threat,vpatch`, and the door
 * serves each kind only to a kernel that names it, so a kernel older than
 * rate rules never reads one as a match rule.
 *
 * Virtual patches (kind `vpatch`): an
 * entry point, a parameter, a typed test and WHO — `{who, conditions:
 * [{target, decode, test, value, ci}]}`, every condition holding, over the
 * request as the platform parsed it (Facts' query, form, JSON, cookies,
 * headers, file names). `who` is anyone, anonymous or lacks:<capability>,
 * and a nonce test asks the platform: what the adapter cannot answer the
 * rule may only observe.
 *
 * Threat addresses (kind `threat`, served to a kernel that names it): a
 * `threats` section beside the rules — `{mode, pass, addresses}`, the
 * addresses codesafe saw send exploits. They are judged first: a request
 * from one that is not signed in and not to a `pass` path (where a person
 * behind the same NAT signs in) is blocked under enforce when the mode is
 * proven, else observed — counted as hits of the reserved rule 0, never
 * reported as an event, so a refusal never feeds the score that listed it.
 *
 * Fast pickup: the door says the project's ruleset version (X-Shield-Version,
 * one value whatever kinds a kernel reads) on every 200 and 304, and the
 * kernel stores it beside the rules. codesafe's ingest answers carry the
 * same version (`shield`); the host hands it to nudge(), which forces the
 * next pull when it differs — a rule switched off in codesafe lands on the
 * next tick after the site's next report, not at the interval.
 *
 * PHP 8.3 floor (the WordPress plugin's, since 0.7.0 — MG 2026-09-23: 8.1 need
 * not be supported). codesafe's own suite tests this
 * file; consumers keep it byte-identical (sha1-compare after sync) and load
 * it with require_once — like any PHP class file, a second include is a
 * fatal error (the classes bind at compile time, so no guard can help).
 *
 * @author Marcin Gil <mg@ovos.at>
 */

namespace Ovos\Codesafe\Shield;

use APCUIterator;
use Closure;
use Throwable;

use function apcu_add;
use function apcu_delete;
use function apcu_enabled;
use function apcu_fetch;
use function apcu_inc;
use function apcu_store;
use function array_is_list;
use function array_key_exists;
use function array_keys;
use function base64_decode;
use function ceil;
use function class_exists;
use function count;
use function crc32;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function hash;
use function http_get_last_response_headers;
use function implode;
use function in_array;
use function inet_ntop;
use function inet_pton;
use function intdiv;
use function is_array;
use function is_bool;
use function is_file;
use function is_int;
use function is_scalar;
use function is_string;
use function json_decode;
use function json_encode;
use function max;
use function ord;
use function parse_str;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function preg_replace;
use function rawurldecode;
use function rename;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strcasecmp;
use function stream_context_create;
use function stripos;
use function strlen;
use function strncasecmp;
use function strpos;
use function strtolower;
use function strtoupper;
use function substr;
use function time;
use function trim;
use function uniqid;
use function unlink;

use const CURLINFO_RESPONSE_CODE;
use const CURLOPT_CONNECTTIMEOUT_MS;
use const CURLOPT_HEADER;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_NOSIGNAL;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT_MS;
use const CURLOPT_URL;
use const CURLOPT_USERAGENT;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const LOCK_EX;

/**
 * What the adapter tells the kernel about the request. The kernel never
 * reads a superglobal; fromServer() takes the arrays as arguments so the
 * shaping — the URI decoded once, the body flattened or capped — is shared
 * and tested while the reading stays the adapter's.
 */
final class Facts
{
	/** a body rule cannot see past this, and the report says so */
	public const BODY_CAP = 65536;
	
	/** a JSON body past this decodes to nothing — padding past BODY_CAP must not hide a json: target */
	public const JSON_CAP = 1048576;
	
	protected ?string $body = null;
	
	protected bool $bodyRead = false;
	
	protected bool $bodyCapped = false;
	
	protected mixed $json = null;
	
	protected bool $jsonRead = false;
	
	/**
	 * @param array<string, mixed> $query the query as the platform parsed it ($_GET)
	 * @param array<string, mixed> $form the form fields as the platform parsed them ($_POST)
	 * @param array<string, string> $headers lowercase names
	 * @param array<string, mixed> $files field => the uploaded file's name (or names)
	 * @param ?Closure(): mixed $jsonReader a JSON body, decoded
	 * @param ?Closure(string): ?bool $can whether the signed-in user has a capability; null when the host cannot say
	 * @param ?Closure(string, string): ?bool $nonce whether a nonce is valid for an action; null when the host cannot say
	 */
	public function __construct(
		public readonly string $method,
		public readonly string $uri,
		public readonly string $ua,
		public readonly string $ip,
		public readonly bool $authed = false,
		protected ?Closure $bodyReader = null,
		public readonly string $user = '',
		public readonly array $query = [],
		public readonly array $form = [],
		public readonly array $cookies = [],
		public readonly array $headers = [],
		public readonly array $files = [],
		protected ?Closure $jsonReader = null,
		protected ?Closure $can = null,
		protected ?Closure $nonce = null,
	)
	{
	}
	
	/**
	 * @param array<string, mixed> $server the request's server parameters ($_SERVER or a PSR-7 equivalent)
	 * @param array<string, mixed> $post the parsed form fields ($_POST) — files never
	 * @param ?callable(): string $input the raw body reader (fn() => file_get_contents('php://input'))
	 * @param string $user who a `user`-keyed rate rule counts: the signed-in identity, or the name a login POST tries ('' = none, the rule is skipped)
	 * @param ?array<string, mixed> $get the parsed query ($_GET); null = the raw URI's query through parse_str
	 * @param array<string, mixed> $cookies the parsed cookies ($_COOKIE)
	 * @param array<string, mixed> $files the uploads ($_FILES) — their names, never their bytes
	 * @param ?callable(string): ?bool $can whether the signed-in user has a capability (a vpatch's `lacks:`)
	 * @param ?callable(string, string): ?bool $nonce whether a nonce is valid for an action (a vpatch's nonce test)
	 */
	public static function fromServer(
		array $server,
		array $post = [],
		?callable $input = null,
		bool $authed = false,
		?string $ip = null,
		string $user = '',
		?array $get = null,
		array $cookies = [],
		array $files = [],
		?callable $can = null,
		?callable $nonce = null,
	): self
	{
		$uri = is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/';
		if($get === null)
		{
			// the RAW query, as PHP would have parsed it — the URI is decoded once below
			parse_str(explode('?', $uri, 2)[1] ?? '', $get);
		}
		$contentType = is_string($server['CONTENT_TYPE'] ?? null) ? $server['CONTENT_TYPE'] : '';
		
		return new self(
			is_string($server['REQUEST_METHOD'] ?? null) ? $server['REQUEST_METHOD'] : 'GET',
			self::decodeOnce($uri),
			is_string($server['HTTP_USER_AGENT'] ?? null) ? $server['HTTP_USER_AGENT'] : '',
			$ip ?? (is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : ''),
			$authed,
			self::bodyReader($contentType, $post, $input),
			$user,
			$get,
			$post,
			$cookies,
			self::headersOf($server),
			self::fileNames($files),
			self::jsonReader($contentType, $input),
			$can === null ? null : Closure::fromCallable($can),
			$nonce === null ? null : Closure::fromCallable($nonce),
		);
	}
	
	/** the request headers by lowercase name, off the server's HTTP_* keys */
	public static function headersOf(
		array $server,
	): array
	{
		$headers = [];
		foreach($server as $key => $value)
		{
			if(is_string($value) && str_starts_with((string)$key, 'HTTP_'))
			{
				$headers[strtolower(str_replace('_', '-', substr((string)$key, 5)))] = $value;
			}
		}
		foreach(['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $key => $name)
		{
			if(is_string($server[$key] ?? null))
			{
				$headers[$name] = $server[$key];
			}
		}
		
		return $headers;
	}
	
	/** $_FILES as names: field => name, a multiple upload's names as a list — the bytes are never read */
	public static function fileNames(
		array $files,
	): array
	{
		$names = [];
		foreach($files as $field => $file)
		{
			if(is_array($file) && isset($file['name']))
			{
				$names[(string)$field] = $file['name'];
			}
		}
		
		return $names;
	}
	
	/** a JSON body, decoded — read only when a json: target asks */
	public static function jsonReader(
		string $contentType,
		?callable $input,
		int $cap = self::JSON_CAP,
	): ?Closure
	{
		$type = strtolower(trim(explode(';', $contentType, 2)[0]));
		if($input === null || ($type !== 'application/json' && str_ends_with($type, '+json') === false))
		{
			return null;
		}
		
		return static function() use ($input, $cap): mixed
		{
			$raw = $input();
			
			return is_string($raw) && strlen($raw) <= $cap ? json_decode($raw, true, 32) : null;
		};
	}
	
	/**
	 * What the body IS to a rule: for a form (urlencoded, multipart) the
	 * parsed fields flattened to `name=value` lines — PHP has parsed them and
	 * multipart's raw stream is gone by then; for JSON, XML and anything
	 * else the raw input, capped at BODY_CAP, decoded once like the URI. Read
	 * lazily: nothing is touched until a body rule asks
	 */
	public static function bodyReader(
		string $contentType,
		array $post,
		?callable $input,
		int $cap = self::BODY_CAP,
	): ?Closure
	{
		$type = strtolower(trim(explode(';', $contentType, 2)[0]));
		if($type === 'application/x-www-form-urlencoded' || $type === 'multipart/form-data')
		{
			return static fn(): array => [self::flatten($post), false];
		}
		if($input === null)
		{
			return null;
		}
		
		return static function() use ($input, $cap): array
		{
			$raw = $input();
			$raw = is_string($raw) ? $raw : '';
			$capped = strlen($raw) > $cap;
			
			return [self::decodeOnce($capped ? substr($raw, 0, $cap) : $raw), $capped];
		};
	}
	
	/** the parsed fields as `name=value` lines, nested arrays as `a[b]=value` */
	public static function flatten(
		array $fields,
		string $prefix = '',
	): string
	{
		$lines = '';
		foreach($fields as $name => $value)
		{
			$key = $prefix === '' ? (string)$name : $prefix . '[' . $name . ']';
			if(is_array($value))
			{
				$lines .= self::flatten($value, $key);
				
				continue;
			}
			$lines .= $key . '=' . (is_string($value) ? $value : (string)json_encode($value)) . "\n";
		}
		
		return $lines;
	}
	
	/** URL-decoding, once — `%2e%2e/` reads as `../`, `%252e` as `%2e` */
	public static function decodeOnce(
		string $value,
	): string
	{
		return rawurldecode($value);
	}
	
	/** the body as a rule reads it; null when the request has none the kernel can read */
	public function body(): ?string
	{
		if($this->bodyRead === false)
		{
			$this->bodyRead = true;
			if($this->bodyReader !== null)
			{
				try
				{
					[$this->body, $this->bodyCapped] = ($this->bodyReader)();
				}
				catch(Throwable)
				{
					$this->body = null;
				}
			}
		}
		
		return $this->body;
	}
	
	public function bodyWasRead(): bool
	{
		return $this->bodyRead;
	}
	
	public function bodyWasCapped(): bool
	{
		return $this->bodyCapped;
	}
	
	/** a JSON body, decoded; null when there is none */
	public function json(): mixed
	{
		if($this->jsonRead === false)
		{
			$this->jsonRead = true;
			try
			{
				$this->json = $this->jsonReader === null ? null : ($this->jsonReader)();
			}
			catch(Throwable)
			{
				$this->json = null;
			}
		}
		
		return $this->json;
	}
	
	/** whether the signed-in user has a capability; null when the host cannot say */
	public function can(
		string $capability,
	): ?bool
	{
		try
		{
			$answer = $this->can === null ? null : ($this->can)($capability);
		}
		catch(Throwable)
		{
			return null;
		}
		
		return is_bool($answer) ? $answer : null;
	}
	
	/** whether a nonce is valid for an action; null when the host cannot say */
	public function nonceValid(
		string $action,
		string $value,
	): ?bool
	{
		try
		{
			$answer = $this->nonce === null ? null : ($this->nonce)($action, $value);
		}
		catch(Throwable)
		{
			return null;
		}
		
		return is_bool($answer) ? $answer : null;
	}
	
	/** the path alone — what a report line names; the query may carry what the body may not */
	public function path(): string
	{
		$path = explode('?', $this->uri, 2)[0];
		
		return $path === '' ? '/' : $path;
	}
	
	/** the subject a field reads — null when this request has none */
	public function field(
		string $field,
	): ?string
	{
		return match($field)
		{
			Ruleset::FIELD_URI => $this->uri,
			Ruleset::FIELD_UA => $this->ua,
			Ruleset::FIELD_IP => $this->ip,
			Ruleset::FIELD_BODY => $this->body(),
			default => null,
		};
	}
}

/**
 * The three switches, read from the host's OWN config on every request and
 * never baked into the cached ruleset — unticking stops the next request,
 * not the next pull. detect = pull and observe; enforce = refuse on a
 * proven rule (inert without detect); kill = nothing, and no network
 */
final class Consent
{
	public function __construct(
		public readonly bool $detect = false,
		public readonly bool $enforce = false,
		public readonly bool $kill = false,
	)
	{
	}
	
	public function active(): bool
	{
		return $this->kill === false && $this->detect;
	}
	
	public function blocks(): bool
	{
		return $this->active() && $this->enforce;
	}
}

/** pass | observe | block — and why, for the log; never a response (status() says which one the adapter sends) */
final class Verdict
{
	public const PASS = 'pass';
	
	public const OBSERVE = 'observe';
	
	public const BLOCK = 'block';
	
	public const KIND_OBSERVE = 'shield_observe';
	
	public const KIND_BLOCK = 'shield_block';
	
	/** a rate rule's verdict, observed or answered — `mode` in the report says which */
	public const KIND_RATE = 'shield_rate';
	
	/**
	 * A proven vpatch held as an observe because the host could not answer
	 * its who or its nonce: a layer that judges before the host can (the
	 * WordPress prepend) leaves such a request to the one that can
	 */
	public const REASON_UNANSWERED = 'unanswered';
	
	/**
	 * @param ?array<string, mixed> $rule the rule that matched, as served
	 * @param string $matched the fragment that matched, capped by the kernel
	 * @param int $count a rate rule's count in its window (the sliding estimate, rounded up)
	 * @param int $retryAfter a rate rule's seconds to the window's end — the 429's Retry-After
	 */
	public function __construct(
		public readonly string $outcome,
		public readonly ?array $rule = null,
		public readonly string $matched = '',
		public readonly string $reason = '',
		public readonly int $count = 0,
		public readonly int $retryAfter = 0,
	)
	{
	}
	
	public static function pass(
		string $reason,
	): self
	{
		return new self(self::PASS, null, '', $reason);
	}
	
	public function isPass(): bool
	{
		return $this->outcome === self::PASS;
	}
	
	public function isBlock(): bool
	{
		return $this->outcome === self::BLOCK;
	}
	
	public function isRate(): bool
	{
		return $this->rule !== null && Ruleset::isRate($this->rule);
	}
	
	/** the security kind the report carries */
	public function kind(): string
	{
		if($this->isRate())
		{
			return self::KIND_RATE;
		}
		
		return $this->outcome === self::BLOCK ? self::KIND_BLOCK : self::KIND_OBSERVE;
	}
	
	/** what the adapter answers: 403 for a match block, 429 (with Retry-After) for a rate block, 200 = carry on */
	public function status(): int
	{
		if($this->isBlock() === false)
		{
			return 200;
		}
		
		return $this->isRate() ? 429 : 403;
	}
}

/**
 * The published ruleset as the kernel holds it: the rules that passed the
 * shape check, the digest the next conditional GET sends back, when it was
 * fetched, and whether the contract is one this kernel knows. An unknown
 * contract or dialect is stored — so the kernel does not hammer the door —
 * and unusable: every request passes
 */
final class Ruleset
{
	public const CONTRACT = 1;
	
	public const DIALECT = 're2-2026';
	
	public const FIELD_URI = 'uri';
	
	public const FIELD_UA = 'ua';
	
	public const FIELD_IP = 'ip';
	
	public const FIELD_BODY = 'body';
	
	public const FIELDS = [self::FIELD_URI, self::FIELD_UA, self::FIELD_IP, self::FIELD_BODY];
	
	public const OP_EQUALS = 'equals';
	
	public const OP_PREFIX = 'prefix';
	
	public const OP_CONTAINS = 'contains';
	
	public const OP_CIDR = 'cidr';
	
	public const OP_REGEX = 'regex';
	
	public const OPS = [self::OP_EQUALS, self::OP_PREFIX, self::OP_CONTAINS, self::OP_CIDR, self::OP_REGEX];
	
	public const MODE_OBSERVE = 'observe';
	
	public const MODE_PROVEN = 'proven';
	
	public const VALUE_MAX = 600;
	
	public const KIND_MATCH = 'match';
	
	public const KIND_RATE = 'rate';
	
	public const KIND_VPATCH = 'vpatch';
	
	public const KINDS = [self::KIND_MATCH, self::KIND_RATE, self::KIND_VPATCH];
	
	/** a vpatch's targets without a name, and the prefixes that take one (`query:file`) */
	public const TARGETS = ['method', 'path'];
	
	/** `request:` is $_REQUEST's reading: the query's values and the body's */
	public const TARGET_PREFIXES = ['query', 'body', 'request', 'json', 'cookie', 'header', 'file', 'query-keys', 'body-keys'];
	
	public const DECODES = ['url', 'base64'];
	
	public const TESTS = ['equals', 'in', 'not_in', 'prefix', 'suffix', 'contains', 'regex', 'numeric', 'not_numeric', 'exists',
		'longer_than', 'nonce_invalid'];
	
	/** the tests whose value is a list, and those that take none */
	public const LIST_TESTS = ['in', 'not_in'];
	
	public const BARE_TESTS = ['numeric', 'not_numeric', 'exists'];
	
	public const CONDITIONS_MAX = 6;
	
	public const LIST_MAX = 50;
	
	public const WHO_ANYONE = 'anyone';
	
	public const WHO_ANONYMOUS = 'anonymous';
	
	public const WHO_LACKS = 'lacks:';
	
	/** the threat-address section's kind, named in X-Shield-Kinds — never a rule's */
	public const KIND_THREAT = 'threat';
	
	/** the rule id a threat address's hits are counted under: a rule's id is never 0 */
	public const THREAT_RULE = 0;
	
	/** at most this many addresses, this many pass paths of at most PASS_MAX bytes */
	public const THREATS_MAX = 5000;
	
	public const PASSES_MAX = 20;
	
	public const PASS_MAX = 100;
	
	/** one address · one user (Facts::$user) · the route itself, whoever asks */
	public const RATE_KEY_IP = 'ip';
	
	public const RATE_KEY_USER = 'user';
	
	public const RATE_KEY_ROUTE = 'route';
	
	public const RATE_KEYS = [self::RATE_KEY_IP, self::RATE_KEY_USER, self::RATE_KEY_ROUTE];
	
	public const RATE_LIMIT_MAX = 1000000;
	
	public const RATE_WINDOW_MAX = 3600;
	
	/**
	 * @param list<array<string, mixed>> $rules
	 */
	public function __construct(
		public readonly array $rules,
		public readonly string $digest,
		public readonly int $fetchedAt,
		public readonly bool $usable,
		public readonly string $project = '',
		public readonly string $version = '',
		public readonly ?array $threats = null,
	)
	{
	}
	
	/**
	 * The door's payload, checked: contract and dialect this kernel knows,
	 * every rule with an int id, a field and an op off the lists, a
	 * non-empty value under VALUE_MAX, a mode — anything else is left out.
	 * The digest is the door's (X-Shield-Sha256) or, absent, the sha256 of
	 * the rules as received — the same bytes the door hashes. The version is
	 * the door's X-Shield-Version, '' when it said none. The threat section is
	 * kept only whole — a mode, pass paths, addresses PHP can read (threats())
	 */
	public static function fromPayload(
		array $payload,
		int $fetchedAt,
		string $digest = '',
		string $version = '',
	): self
	{
		$usable = ($payload['contract'] ?? null) === self::CONTRACT
			&& ($payload['dialect'] ?? null) === self::DIALECT
			&& is_array($payload['rules'] ?? null);
		$rules = [];
		if($usable)
		{
			foreach($payload['rules'] as $rule)
			{
				if(self::wellFormed($rule))
				{
					$rules[] = $rule;
				}
			}
		}
		if(preg_match('~^[0-9a-f]{64}$~', $digest) !== 1)
		{
			$digest = hash('sha256', (string)json_encode(is_array($payload['rules'] ?? null) ? $payload['rules'] : [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
				. (isset($payload['threats']) ? (string)json_encode($payload['threats'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ''));
		}
		
		return new self($rules, $digest, $fetchedAt, $usable, is_string($payload['project'] ?? null) ? $payload['project'] : '',
			self::isVersion($version) ? $version : '', $usable ? self::threats($payload['threats'] ?? null) : null);
	}
	
	/** the stored record back into a ruleset; null when the record is not one */
	public static function fromRecord(
		mixed $record,
	): ?self
	{
		if(is_array($record) === false || is_array($record['rules'] ?? null) === false || is_int($record['fetched_at'] ?? null) === false)
		{
			return null;
		}
		
		return new self(
			$record['rules'],
			is_string($record['sha256'] ?? null) ? $record['sha256'] : '',
			$record['fetched_at'],
			($record['usable'] ?? false) === true,
			is_string($record['project'] ?? null) ? $record['project'] : '',
			is_string($record['version'] ?? null) && self::isVersion($record['version']) ? $record['version'] : '',
			// stored as checked (a lookup map): read per request, never checked again
			is_array($record['threats']['addresses'] ?? null) ? $record['threats'] : null,
		);
	}
	
	/**
	 * The door's threat section, checked: a mode off the list, at most
	 * PASSES_MAX pass paths (each `/…`, under PASS_MAX bytes), at most
	 * THREATS_MAX addresses in their canonical form as a lookup map — an
	 * address PHP cannot read is left out. Null when there is none
	 *
	 * @return ?array{mode: string, pass: list<string>, addresses: array<string, int>}
	 */
	public static function threats(
		mixed $section,
	): ?array
	{
		if(is_array($section) === false || in_array($section['mode'] ?? null, [self::MODE_OBSERVE, self::MODE_PROVEN], true) === false
			|| is_array($section['addresses'] ?? null) === false)
		{
			return null;
		}
		$pass = [];
		foreach(is_array($section['pass'] ?? null) ? $section['pass'] : [] as $path)
		{
			if(is_string($path) && str_starts_with($path, '/') && strlen($path) <= self::PASS_MAX && count($pass) < self::PASSES_MAX)
			{
				$pass[] = $path;
			}
		}
		$addresses = [];
		foreach($section['addresses'] as $address)
		{
			$canonical = is_string($address) ? self::canonical($address) : '';
			if($canonical !== '')
			{
				$addresses[$canonical] = 1;
			}
			if(count($addresses) >= self::THREATS_MAX)
			{
				break;
			}
		}
		
		return ['mode' => $section['mode'], 'pass' => $pass, 'addresses' => $addresses];
	}
	
	/**
	 * An address in the one form every side compares: the `::ffff:` form
	 * unwrapped to its v4 address, then inet_ntop(inet_pton()) — RFC 5952 for
	 * v6, as the Node twin writes it; '' when it is none
	 */
	public static function canonical(
		string $address,
	): string
	{
		$address = trim($address);
		if(preg_match('~^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$~i', $address, $mapped) === 1)
		{
			$address = $mapped[1];
		}
		$packed = @inet_pton($address);
		
		return $packed === false ? '' : (string)inet_ntop($packed);
	}
	
	/** what the store keeps — JSON, never PHP */
	public function toRecord(): array
	{
		return [
			'contract' => self::CONTRACT,
			'dialect' => self::DIALECT,
			'project' => $this->project,
			'fetched_at' => $this->fetchedAt,
			'sha256' => $this->digest,
			'version' => $this->version,
			'threats' => $this->threats,
			'usable' => $this->usable,
			'rules' => $this->rules,
		];
	}
	
	/** renewed by a 304 — with the version it said, when it said one */
	public function withFetchedAt(
		int $fetchedAt,
		string $version = '',
	): self
	{
		return new self($this->rules, $this->digest, $fetchedAt, $this->usable, $this->project,
			self::isVersion($version) ? $version : $this->version, $this->threats);
	}
	
	/** a version is the door's sha256, lowercase hex */
	public static function isVersion(
		string $version,
	): bool
	{
		return preg_match('~^[0-9a-f]{64}$~', $version) === 1;
	}
	
	/** past the ceiling: a codesafe instance gone silent lifts its shield */
	public function isStale(
		int $now,
		int $ceiling,
	): bool
	{
		return $this->fetchedAt + $ceiling <= $now;
	}
	
	public static function wellFormed(
		mixed $rule,
	): bool
	{
		if(is_array($rule) && self::isVpatch($rule))
		{
			return self::vpatchWellFormed($rule);
		}
		
		return is_array($rule)
			&& is_int($rule['id'] ?? null)
			&& in_array($rule['field'] ?? null, self::FIELDS, true)
			&& in_array($rule['op'] ?? null, self::OPS, true)
			&& is_string($rule['value'] ?? null) && trim($rule['value']) !== '' && strlen($rule['value']) <= self::VALUE_MAX
			&& (isset($rule['ci']) === false || is_bool($rule['ci']))
			&& is_string($rule['mode'] ?? null)
			&& (isset($rule['kind']) === false || in_array($rule['kind'], self::KINDS, true))
			&& (self::isRate($rule) === false || self::rateWellFormed($rule['rate'] ?? null));
	}
	
	public static function isVpatch(
		array $rule,
	): bool
	{
		return ($rule['kind'] ?? self::KIND_MATCH) === self::KIND_VPATCH;
	}
	
	/** a vpatch: an int id, a mode, a `who` off the vocabulary, 1 to CONDITIONS_MAX conditions each well formed */
	public static function vpatchWellFormed(
		array $rule,
	): bool
	{
		$conditions = $rule['conditions'] ?? null;
		if(is_int($rule['id'] ?? null) === false || is_string($rule['mode'] ?? null) === false
			|| self::whoWellFormed($rule['who'] ?? null) === false
			|| is_array($conditions) === false || array_is_list($conditions) === false
			|| count($conditions) < 1 || count($conditions) > self::CONDITIONS_MAX)
		{
			return false;
		}
		foreach($conditions as $condition)
		{
			if(self::conditionWellFormed($condition) === false)
			{
				return false;
			}
		}
		
		return true;
	}
	
	public static function whoWellFormed(
		mixed $who,
	): bool
	{
		return $who === self::WHO_ANYONE || $who === self::WHO_ANONYMOUS
			|| (is_string($who) && preg_match('~^lacks:[a-z0-9_]{1,64}$~D', $who) === 1);
	}
	
	/** a target off the vocabulary; a decode list off its own; a value the test can read — a list, a count, or a bounded string */
	public static function conditionWellFormed(
		mixed $condition,
	): bool
	{
		if(is_array($condition) === false || self::targetWellFormed($condition['target'] ?? null) === false
			|| in_array($condition['test'] ?? null, self::TESTS, true) === false
			|| (isset($condition['ci']) && is_bool($condition['ci']) === false))
		{
			return false;
		}
		$decode = $condition['decode'] ?? [];
		if(is_array($decode) === false || array_is_list($decode) === false || count($decode) > count(self::DECODES))
		{
			return false;
		}
		foreach($decode as $step)
		{
			if(in_array($step, self::DECODES, true) === false)
			{
				return false;
			}
		}
		$test = $condition['test'];
		$value = $condition['value'] ?? null;
		if(in_array($test, self::BARE_TESTS, true))
		{
			return true;
		}
		if(in_array($test, self::LIST_TESTS, true))
		{
			if(is_array($value) === false || array_is_list($value) === false || $value === [] || count($value) > self::LIST_MAX)
			{
				return false;
			}
			foreach($value as $item)
			{
				if(is_string($item) === false || strlen($item) > self::VALUE_MAX)
				{
					return false;
				}
			}
			
			return true;
		}
		if($test === 'longer_than')
		{
			return is_int($value) && $value >= 0 && $value <= Facts::BODY_CAP;
		}
		
		return is_string($value) && trim($value) !== '' && strlen($value) <= self::VALUE_MAX;
	}
	
	/** `method`, `path`, or a prefix and a name (`query:file`); the keys targets may name the top level ('') */
	public static function targetWellFormed(
		mixed $target,
	): bool
	{
		if(is_string($target) === false || strlen($target) > 200)
		{
			return false;
		}
		if(in_array($target, self::TARGETS, true))
		{
			return true;
		}
		[$prefix, $name] = explode(':', $target, 2) + [1 => null];
		
		return in_array($prefix, self::TARGET_PREFIXES, true) && is_string($name)
			&& ($name !== '' || $prefix === 'query-keys' || $prefix === 'body-keys');
	}
	
	/** no `kind` key is a match rule — every rule a kernel older than rate rules ever saw */
	public static function isRate(
		array $rule,
	): bool
	{
		return ($rule['kind'] ?? self::KIND_MATCH) === self::KIND_RATE;
	}
	
	public static function rateWellFormed(
		mixed $rate,
	): bool
	{
		return is_array($rate)
			&& in_array($rate['key'] ?? null, self::RATE_KEYS, true)
			&& is_int($rate['limit'] ?? null) && $rate['limit'] >= 1 && $rate['limit'] <= self::RATE_LIMIT_MAX
			&& is_int($rate['window'] ?? null) && $rate['window'] >= 1 && $rate['window'] <= self::RATE_WINDOW_MAX;
	}
}

/**
 * Two tiers. Fast: APCu under a per-install prefix ('' = none). Durable:
 * a JSON file the pull writes with write-temp-and-rename into a writable,
 * non-web-served directory the adapter names ('' = none). A read that
 * misses APCu reads the file and re-primes APCu, so a cleared APCu costs
 * an attacker nothing; a host without APCu runs on the file alone. Positive
 * empty: an empty rules array is knowledge and is cached like anything
 * else; a MISSING store is ignorance — read() answers null, the kernel
 * passes the request and forces the next pull. The lock and the counters
 * are APCu-only; losing them costs one duplicate pull or a minute's counts
 */
final class Store
{
	public const LOCK_TTL = 30;
	
	/** counters live three minutes: the flush that folds them runs every one */
	public const HITS_TTL = 180;
	
	protected bool $apcu;
	
	protected bool $forced = false;
	
	public function __construct(
		public readonly string $file = '',
		public readonly string $prefix = '',
		?bool $apcu = null,
	)
	{
		$this->apcu = $apcu ?? ($prefix !== '' && function_exists('apcu_enabled') && apcu_enabled());
	}
	
	public function hasApcu(): bool
	{
		return $this->apcu;
	}
	
	/** the record, from APCu, else from the file (re-priming APCu); null = no store */
	public function read(): ?array
	{
		if($this->apcu)
		{
			$hit = apcu_fetch($this->key('rules'));
			if(is_array($hit))
			{
				return $hit;
			}
		}
		if($this->file === '' || is_file($this->file) === false)
		{
			return null;
		}
		try
		{
			$record = json_decode((string)file_get_contents($this->file), true);
		}
		catch(Throwable)
		{
			return null;
		}
		if(is_array($record) === false)
		{
			return null;
		}
		if($this->apcu)
		{
			apcu_store($this->key('rules'), $record);
		}
		
		return $record;
	}
	
	/** both tiers; true when at least one took it */
	public function write(
		array $record,
	): bool
	{
		$written = false;
		if($this->file !== '')
		{
			$temp = $this->file . '.' . uniqid('', true) . '.tmp';
			try
			{
				$json = (string)json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
				if(file_put_contents($temp, $json, LOCK_EX) === strlen($json) && rename($temp, $this->file))
				{
					$written = true;
				}
				elseif(is_file($temp))
				{
					unlink($temp);
				}
			}
			catch(Throwable)
			{
				if(is_file($temp))
				{
					@unlink($temp);
				}
			}
		}
		if($this->apcu)
		{
			$written = apcu_store($this->key('rules'), $record) || $written;
		}
		if($written)
		{
			$this->forcePull(false);
		}
		
		return $written;
	}
	
	/** one puller per pool: apcu_add wins once; without APCu everyone may pull (a duplicate GET is harmless) */
	public function lock(
		string $name,
		int $ttl = self::LOCK_TTL,
	): bool
	{
		if($this->apcu === false)
		{
			return true;
		}
		
		return apcu_add($this->key('lock:' . $name), 1, $ttl);
	}
	
	public function unlock(
		string $name,
	): void
	{
		if($this->apcu)
		{
			apcu_delete($this->key('lock:' . $name));
		}
	}
	
	/** ignorance seen: the very next tick pulls regardless of the interval (APCu, and this instance for a host without) */
	public function forcePull(
		bool $on = true,
	): void
	{
		$this->forced = $on;
		if($this->apcu)
		{
			if($on)
			{
				apcu_store($this->key('force'), 1, 3600);
			}
			else
			{
				apcu_delete($this->key('force'));
			}
		}
	}
	
	public function pullForced(): bool
	{
		if($this->forced)
		{
			return true;
		}
		
		return $this->apcu && apcu_fetch($this->key('force')) === 1;
	}
	
	/** one more hit for a rule in this minute — uncapped, the truth the events cannot carry */
	public function hit(
		int $minute,
		int $ruleId,
		string $outcome,
	): void
	{
		if($this->apcu === false)
		{
			return;
		}
		$key = $this->key('hits:' . $minute . ':' . $ruleId . ':' . $outcome);
		if(apcu_inc($key, 1, $success, self::HITS_TTL) === false || $success === false)
		{
			apcu_add($key, 1, self::HITS_TTL);
		}
	}
	
	/**
	 * One more request for a rate rule's key, and the count in its window: fixed
	 * windows, two adjacent buckets — the current bucket's count plus the
	 * previous one's weighted by how much of it the sliding window still
	 * covers. Null without APCu: the file tier cannot count, and the rule is
	 * skipped
	 */
	public function rate(
		int $ruleId,
		string $key,
		int $window,
		int $now,
	): ?float
	{
		if($this->apcu === false || $window < 1)
		{
			return null;
		}
		$bucket = intdiv($now, $window);
		$head = 'rate:' . $ruleId . ':' . crc32($key) . ':';
		$ttl = 2 * $window + 1;
		$name = $this->key($head . $bucket);
		$current = apcu_inc($name, 1, $success, $ttl);
		if($current === false || $success === false)
		{
			$current = apcu_add($name, 1, $ttl) ? 1 : (int)apcu_inc($name, 1, $success, $ttl);
		}
		$previous = apcu_fetch($this->key($head . ($bucket - 1)));
		$covered = 1 - ($now - $bucket * $window) / $window;
		
		return (float)$current + (is_int($previous) ? $previous * $covered : 0.0);
	}
	
	/** true the first time a name is claimed within its ttl — the report-once gate; false without APCu */
	public function once(
		string $name,
		int $ttl,
	): bool
	{
		return $this->apcu && apcu_add($this->key('once:' . $name), 1, max(1, $ttl));
	}
	
	/**
	 * A minute's counters, taken (fetched and deleted) for the fragment that
	 * ships them: [rule id => ['observe' => n, 'block' => n]]
	 *
	 * @return array<int, array{observe: int, block: int}>
	 */
	public function takeHits(
		int $minute,
	): array
	{
		if($this->apcu === false || class_exists(APCUIterator::class) === false)
		{
			return [];
		}
		$hits = [];
		$head = $this->key('hits:' . $minute . ':');
		foreach(new APCUIterator('~^' . preg_quote($head, '~') . '~') as $entry)
		{
			$key = (string)$entry['key'];
			[$ruleId, $outcome] = explode(':', substr($key, strlen($head)), 2) + [1 => ''];
			$id = (int)$ruleId;
			$hits[$id] ??= ['observe' => 0, 'block' => 0];
			if(isset($hits[$id][$outcome]))
			{
				$hits[$id][$outcome] += (int)$entry['value'];
			}
			apcu_delete($key);
		}
		
		return $hits;
	}
	
	protected function key(
		string $name,
	): string
	{
		return $this->prefix . ':' . $name;
	}
}

/**
 * pull() · judge() · report(). See the file header
 */
final class Kernel
{
	/** the pull interval — resolution latency: a resolved finding lifts its rule within this */
	public const INTERVAL = 300;
	
	/** the ceiling — a codesafe instance gone silent this long lifts the shield; a codesafe incident does not disarm the fleet */
	public const CEILING = 86400;
	
	public const TIMEOUT_MS = 2000;
	
	/** `matched` is attacker-controlled: bounded here, scrubbed by the sender */
	public const MATCHED_MAX = 200;
	
	public const DOOR = '/api/v1/shield';
	
	public const USER_AGENT = 'ovos-codesafe-shield/1';
	
	/** what this kernel reads — the door serves rate rules only to a pull that names them */
	public const HEADER_KINDS = 'X-Shield-Kinds';
	
	public const KINDS = 'match,rate,threat,vpatch';
	
	/** the project's ruleset version, one value whatever kinds a kernel reads — what nudge() compares */
	public const HEADER_VERSION = 'X-Shield-Version';
	
	public const PULL_OFF = 'off';
	
	public const PULL_SKIPPED = 'skipped';
	
	public const PULL_LOCKED = 'locked';
	
	public const PULL_FRESH = 'fresh';
	
	public const PULL_UNCHANGED = 'unchanged';
	
	public const PULL_REFUSED = 'refused';
	
	public const PULL_FAILED = 'failed';
	
	protected ?Closure $http;
	
	protected ?Closure $report;
	
	protected ?Closure $log;
	
	protected ?Ruleset $ruleset = null;
	
	protected bool $rulesetRead = false;
	
	/**
	 * @param string $url codesafe instance base URL
	 * @param string $key the project api_key
	 * @param ?callable(string $url, array<string> $headers, int $timeoutMs): array{status: int, headers: array<string, string>, body: string} $http the transport; null = curl or streams
	 * @param ?callable(string $kind, string $message, array<string, mixed> $extra): mixed $report the host's sender
	 * @param ?callable(string $line): mixed $log the host's log
	 */
	public function __construct(
		protected string $url,
		protected string $key,
		protected Store $store,
		?callable $http = null,
		?callable $report = null,
		?callable $log = null,
		protected int $interval = self::INTERVAL,
		protected int $ceiling = self::CEILING,
		protected int $timeoutMs = self::TIMEOUT_MS,
	)
	{
		$this->url = rtrim($url, '/');
		$this->http = $http === null ? null : Closure::fromCallable($http);
		$this->report = $report === null ? null : Closure::fromCallable($report);
		$this->log = $log === null ? null : Closure::fromCallable($log);
	}
	
	public function store(): Store
	{
		return $this->store;
	}
	
	/** the cached ruleset, read once per request; null = no store (ignorance) */
	public function ruleset(): ?Ruleset
	{
		if($this->rulesetRead === false)
		{
			$this->rulesetRead = true;
			try
			{
				$this->ruleset = Ruleset::fromRecord($this->store->read());
			}
			catch(Throwable)
			{
				$this->ruleset = null;
			}
		}
		
		return $this->ruleset;
	}
	
	/**
	 * Over the cached ruleset. pass when consent says so, when there is no
	 * store (and the next tick will pull), when the contract is unknown, when
	 * the ruleset is past the ceiling; else the first MATCH or VPATCH rule
	 * that matches — in the door's order, proven first — as block (proven AND
	 * enforce, a vpatch's who and nonce answered) or observe; a vpatch that
	 * could not be answered observes unless a later rule blocks; else the
	 * first RATE rule whose predicate matches and whose key
	 * is over its limit, the same way. A rule the engine cannot run matches
	 * nothing. The one side effect: a rate rule's predicate that matches
	 * counts the request
	 */
	public function judge(
		Facts $facts,
		Consent $consent,
		?int $now = null,
	): Verdict
	{
		try
		{
			if($consent->kill)
			{
				return Verdict::pass('kill');
			}
			if($consent->detect === false)
			{
				return Verdict::pass('detect off');
			}
			$ruleset = $this->ruleset();
			if($ruleset === null)
			{
				$this->store->forcePull();
				
				return Verdict::pass('no ruleset');
			}
			if($ruleset->usable === false)
			{
				return Verdict::pass('unknown contract');
			}
			if($ruleset->isStale($now ?? time(), $this->ceiling))
			{
				$this->store->forcePull();
				
				return Verdict::pass('ceiling');
			}
			$threat = $this->threatened($ruleset, $facts);
			if($threat !== null)
			{
				$blocks = $threat['mode'] === Ruleset::MODE_PROVEN && $consent->blocks();
				
				return new Verdict($blocks ? Verdict::BLOCK : Verdict::OBSERVE, $threat, $facts->ip);
			}
			$rates = [];
			$held = null;
			foreach($ruleset->rules as $rule)
			{
				if(Ruleset::isRate($rule))
				{
					$rates[] = $rule;
					
					continue;
				}
				if(Ruleset::isVpatch($rule))
				{
					$hit = self::patched($rule, $facts);
					if($hit === null)
					{
						continue;
					}
					$blocks = ($rule['mode'] ?? '') === Ruleset::MODE_PROVEN && $consent->blocks();
					$verdict = new Verdict($blocks && $hit['resolved'] ? Verdict::BLOCK : Verdict::OBSERVE, $rule,
						substr($hit['matched'], 0, self::MATCHED_MAX));
					// what the host could not answer (who, a nonce) only observes — and a proven rule after it may still block
					if($blocks && $hit['resolved'] === false)
					{
						$held ??= new Verdict(Verdict::OBSERVE, $rule, $verdict->matched, Verdict::REASON_UNANSWERED);
						
						continue;
					}
					
					return $verdict->isBlock() ? $verdict : ($held ?? $verdict);
				}
				$matched = $this->matched($rule, $facts);
				if($matched === null)
				{
					continue;
				}
				$blocks = ($rule['mode'] ?? '') === Ruleset::MODE_PROVEN && $consent->blocks();
				$verdict = new Verdict($blocks ? Verdict::BLOCK : Verdict::OBSERVE, $rule, substr($matched, 0, self::MATCHED_MAX));
				
				return $verdict->isBlock() ? $verdict : ($held ?? $verdict);
			}
			if($held !== null)
			{
				return $held;
			}
			$now ??= time();
			foreach($rates as $rule)
			{
				$verdict = $this->rated($rule, $facts, $consent, $now);
				if($verdict !== null)
				{
					return $verdict;
				}
			}
			
			return Verdict::pass('no match');
		}
		catch(Throwable $e)
		{
			$this->log('judge failed open: ' . $e->getMessage());
			
			return Verdict::pass('exception');
		}
	}
	
	/**
	 * The threat section's word on this request: the pseudo-rule it is judged
	 * by (id THREAT_RULE, the section's mode) when the address is listed, the
	 * request is not signed in and its path ends in none of the pass paths;
	 * null otherwise
	 *
	 * @return ?array{id: int, kind: string, mode: string}
	 */
	protected function threatened(
		Ruleset $ruleset,
		Facts $facts,
	): ?array
	{
		$threats = $ruleset->threats;
		if($threats === null || $facts->authed || isset($threats['addresses'][Ruleset::canonical($facts->ip)]) === false)
		{
			return null;
		}
		$path = $facts->path();
		foreach((array)($threats['pass'] ?? []) as $pass)
		{
			if(str_ends_with($path, (string)$pass))
			{
				return null;
			}
		}
		
		return ['id' => Ruleset::THREAT_RULE, 'kind' => Ruleset::KIND_THREAT, 'mode' => (string)$threats['mode']];
	}
	
	/** a rule's predicate over the request: the fragment, or null */
	protected function matched(
		array $rule,
		Facts $facts,
	): ?string
	{
		$subject = $facts->field((string)$rule['field']);
		
		return $subject === null ? null : self::match($rule, $subject);
	}
	
	/**
	 * A rate rule: its predicate, its key, one more in its window — a verdict
	 * past the limit, else null. A `user` rule on a request with no user and a
	 * store without APCu count nothing
	 */
	protected function rated(
		array $rule,
		Facts $facts,
		Consent $consent,
		int $now,
	): ?Verdict
	{
		$matched = $this->matched($rule, $facts);
		if($matched === null)
		{
			return null;
		}
		$rate = (array)$rule['rate'];
		$key = match($rate['key'])
		{
			Ruleset::RATE_KEY_IP => $facts->ip,
			Ruleset::RATE_KEY_USER => $facts->user,
			default => '*',
		};
		if($key === '')
		{
			return null;
		}
		$window = (int)$rate['window'];
		$count = $this->store->rate((int)$rule['id'], $key, $window, $now);
		if($count === null || $count <= (int)$rate['limit'])
		{
			return null;
		}
		$blocks = ($rule['mode'] ?? '') === Ruleset::MODE_PROVEN && $consent->blocks();
		
		return new Verdict($blocks ? Verdict::BLOCK : Verdict::OBSERVE, $rule, substr($matched, 0, self::MATCHED_MAX),
			'over limit', (int)ceil($count), $window - ($now % $window));
	}
	
	/** judge, count, report — the adapter turns block into its own 403, or a rate block into its 429 */
	public function handle(
		Facts $facts,
		Consent $consent,
		?int $now = null,
	): Verdict
	{
		$verdict = $this->judge($facts, $consent, $now);
		if($verdict->isPass() === false)
		{
			$this->count($verdict, $now);
			$this->report($verdict, $facts);
		}
		
		return $verdict;
	}
	
	/** the match through the adapter's sender as a security event; never throws */
	public function report(
		Verdict $verdict,
		Facts $facts,
	): void
	{
		// a threat address is counted, never reported: the event would feed the
		// score that listed it, and the block would renew itself
		if($this->report === null || $verdict->isPass() || $verdict->rule === null
			|| ($verdict->rule['kind'] ?? '') === Ruleset::KIND_THREAT)
		{
			return;
		}
		try
		{
			$rule = $verdict->rule;
			if($verdict->isRate())
			{
				$this->reportRate($verdict, $facts);
				
				return;
			}
			($this->report)($verdict->kind(), sprintf('shield rule %d %s %s %s', (int)$rule['id'],
				$verdict->isBlock() ? 'refused' : 'observed', $facts->method, substr($facts->path(), 0, 200)), [
				'rule' => (int)$rule['id'],
				'finding' => is_string($rule['finding'] ?? null) ? $rule['finding'] : '',
				'cve' => is_string($rule['cve'] ?? null) ? $rule['cve'] : '',
				'origin' => is_string($rule['origin'] ?? null) ? $rule['origin'] : '',
				// a vpatch has no field or op: its conditions name what it reads, `who` whom
				'field' => Ruleset::isVpatch($rule) ? Ruleset::KIND_VPATCH : (string)$rule['field'],
				'op' => Ruleset::isVpatch($rule) ? 'conditions' : (string)$rule['op'],
				'mode' => (string)$rule['mode'],
				'matched' => $verdict->matched,
				'authed' => $facts->authed,
				'body_capped' => $facts->bodyWasCapped(),
			] + (Ruleset::isVpatch($rule) ? ['who' => (string)($rule['who'] ?? '')] : []));
		}
		catch(Throwable $e)
		{
			$this->log('report failed: ' . $e->getMessage());
		}
	}
	
	/**
	 * A rate verdict, ONCE per rule, key and window — the hits count every
	 * request past the limit, the event says it started. Never "refused": the
	 * codesafe's alarm reads `shield rule N refused` as a match rule turning
	 * people away, and a route ceiling doing its job is not that
	 */
	protected function reportRate(
		Verdict $verdict,
		Facts $facts,
	): void
	{
		$rule = (array)$verdict->rule;
		$rate = (array)$rule['rate'];
		$window = (int)$rate['window'];
		$key = match($rate['key'])
		{
			Ruleset::RATE_KEY_IP => $facts->ip,
			Ruleset::RATE_KEY_USER => $facts->user,
			default => '*',
		};
		// claimed until the window ends: the next window's first request past the limit reports again
		if($this->store->once('rate:' . (int)$rule['id'] . ':' . crc32($key), max(1, $verdict->retryAfter)) === false)
		{
			return;
		}
		($this->report)(Verdict::KIND_RATE, sprintf('shield rule %d %s %s %s (%d/%d per %ds)', (int)$rule['id'],
			$verdict->isBlock() ? 'limited' : 'over-limit', $facts->method, substr($facts->path(), 0, 200),
			$verdict->count, (int)$rate['limit'], $window), [
			'rule' => (int)$rule['id'],
			'finding' => is_string($rule['finding'] ?? null) ? $rule['finding'] : '',
			'cve' => is_string($rule['cve'] ?? null) ? $rule['cve'] : '',
			'origin' => is_string($rule['origin'] ?? null) ? $rule['origin'] : '',
			'field' => (string)$rule['field'],
			'op' => (string)$rule['op'],
			'mode' => (string)$rule['mode'],
			'matched' => $verdict->matched,
			'key' => (string)$rate['key'],
			'limit' => (int)$rate['limit'],
			'window' => $window,
			'count' => $verdict->count,
			'authed' => $facts->authed,
		]);
	}
	
	/** the uncapped per-rule counter for this minute */
	public function count(
		Verdict $verdict,
		?int $now = null,
	): void
	{
		if($verdict->isPass() || $verdict->rule === null)
		{
			return;
		}
		try
		{
			$this->store->hit(intdiv($now ?? time(), 60), (int)$verdict->rule['id'], $verdict->outcome);
		}
		catch(Throwable)
		{
		}
	}
	
	/**
	 * A codesafe answer said the project's ruleset version (the ingest
	 * answers' `shield`): when the stored ruleset's version is known and
	 * differs, the next pull is forced — no network here, the host's tick
	 * pulls. Nothing stored, a version never said, or a malformed one compare
	 * nothing. True when it forced
	 */
	public function nudge(
		string $version,
	): bool
	{
		try
		{
			$current = $this->ruleset();
			if(Ruleset::isVersion($version) === false || $current === null
				|| $current->version === '' || $current->version === $version)
			{
				return false;
			}
			$this->store->forcePull();
			
			return true;
		}
		catch(Throwable)
		{
			return false;
		}
	}
	
	/**
	 * The conditional GET, on the host's tick: off under kill or without
	 * detect (no network); skipped inside the interval unless forced;
	 * locked when another request of the pool is pulling; fresh (200,
	 * stored), unchanged (304, the ceiling renewed), refused (a contract this
	 * kernel does not know — stored unusable so the door is not hammered),
	 * failed (anything else — the cache is left alone)
	 */
	public function pull(
		Consent $consent,
		?int $now = null,
		bool $force = false,
	): string
	{
		if($consent->active() === false)
		{
			return self::PULL_OFF;
		}
		$now ??= time();
		try
		{
			$current = Ruleset::fromRecord($this->store->read());
			if($force === false && $current !== null && $this->store->pullForced() === false && $current->fetchedAt + $this->interval > $now)
			{
				return self::PULL_SKIPPED;
			}
			if($this->store->lock('pull') === false)
			{
				return self::PULL_LOCKED;
			}
			try
			{
				return $this->fetch($current, $now);
			}
			finally
			{
				$this->store->unlock('pull');
			}
		}
		catch(Throwable $e)
		{
			$this->log('pull failed: ' . $e->getMessage());
			
			return self::PULL_FAILED;
		}
	}
	
	protected function fetch(
		?Ruleset $current,
		int $now,
	): string
	{
		// both names of the key header: X-Console-Key is what an instance from
		// before the rename (2026-09-24) reads, X-Codesafe-Key every one since
		$headers = ['X-Codesafe-Key: ' . $this->key, 'X-Console-Key: ' . $this->key, 'Accept: application/json',
			self::HEADER_KINDS . ': ' . self::KINDS];
		if($current !== null && $current->digest !== '')
		{
			$headers[] = 'If-None-Match: "' . $current->digest . '"';
		}
		$response = ($this->http ?? Closure::fromCallable([$this, 'transport']))($this->url . self::DOOR, $headers, $this->timeoutMs);
		$status = (int)($response['status'] ?? 0);
		if($status === 304 && $current !== null)
		{
			$this->store->write($current->withFetchedAt($now, self::header($response, self::HEADER_VERSION))->toRecord());
			$this->rulesetRead = false;
			
			return self::PULL_UNCHANGED;
		}
		if($status !== 200)
		{
			$this->log('pull answered ' . $status);
			
			return self::PULL_FAILED;
		}
		$payload = json_decode((string)($response['body'] ?? ''), true);
		if(is_array($payload) === false)
		{
			$this->log('pull answered no JSON');
			
			return self::PULL_FAILED;
		}
		$ruleset = Ruleset::fromPayload($payload, $now, self::header($response, 'X-Shield-Sha256'),
			self::header($response, self::HEADER_VERSION));
		$this->store->write($ruleset->toRecord());
		$this->rulesetRead = false;
		if($ruleset->usable === false)
		{
			$this->log('pull refused: contract ' . json_encode($payload['contract'] ?? null) . ' dialect ' . json_encode($payload['dialect'] ?? null));
			
			return self::PULL_REFUSED;
		}
		
		return self::PULL_FRESH;
	}
	
	/** one header of the transport's answer, any case; '' when absent */
	protected static function header(
		array $response,
		string $name,
	): string
	{
		foreach((array)($response['headers'] ?? []) as $key => $value)
		{
			if(strtolower((string)$key) === strtolower($name) && is_string($value))
			{
				return trim($value);
			}
		}
		
		return '';
	}
	
	/**
	 * A vpatch over the request: the value that passed (a parameter's, else
	 * the frame's) and whether all of it was answered — `who` and any nonce
	 * test — or null when it does not hold. A request that is not signed in
	 * lacks every capability; a signed-in one asks Facts::can()
	 *
	 * @return ?array{matched: string, resolved: bool}
	 */
	public static function patched(
		array $rule,
		Facts $facts,
	): ?array
	{
		$resolved = true;
		$who = (string)($rule['who'] ?? '');
		if($who === Ruleset::WHO_ANONYMOUS && $facts->authed)
		{
			return null;
		}
		if(str_starts_with($who, Ruleset::WHO_LACKS) && $facts->authed)
		{
			$can = $facts->can(substr($who, strlen(Ruleset::WHO_LACKS)));
			if($can === true)
			{
				return null;
			}
			$resolved = $can === false;
		}
		$matched = '';
		$framed = '';
		foreach((array)($rule['conditions'] ?? []) as $condition)
		{
			$hit = self::condition($condition, $facts);
			if($hit === null)
			{
				return null;
			}
			$resolved = $resolved && $hit['resolved'];
			if(in_array($condition['target'], Ruleset::TARGETS, true))
			{
				$framed = $framed === '' ? $hit['matched'] : $framed;
			}
			else if($matched === '')
			{
				$matched = $hit['matched'];
			}
		}
		
		return ['matched' => $matched !== '' ? $matched : $framed, 'resolved' => $resolved];
	}
	
	/**
	 * One condition: the value that passed it and whether it was answered, or
	 * null when none passes. Any value of a target with several (an array, a
	 * wildcard, the keys) is enough; an absent one never holds — but a nonce
	 * that is not there is an invalid one, and a present one needs the host
	 *
	 * @return ?array{matched: string, resolved: bool}
	 */
	public static function condition(
		array $condition,
		Facts $facts,
	): ?array
	{
		$values = self::values((string)$condition['target'], $facts);
		$test = (string)$condition['test'];
		if($test === 'exists')
		{
			return $values === [] ? null : ['matched' => $values[0], 'resolved' => true];
		}
		if($test === 'nonce_invalid')
		{
			if($values === [])
			{
				return ['matched' => '', 'resolved' => true];
			}
			$resolved = true;
			foreach($values as $value)
			{
				$valid = $facts->nonceValid((string)$condition['value'], $value);
				if($valid === true)
				{
					return null;
				}
				$resolved = $resolved && $valid === false;
			}
			
			return ['matched' => $values[0], 'resolved' => $resolved];
		}
		foreach($values as $value)
		{
			$decoded = self::decoded($value, (array)($condition['decode'] ?? []));
			if($decoded !== null && self::passes($test, $decoded, $condition))
			{
				return ['matched' => $decoded, 'resolved' => true];
			}
		}
		
		return null;
	}
	
	/**
	 * A target's values over the request
	 *
	 * @return list<string>
	 */
	public static function values(
		string $target,
		Facts $facts,
	): array
	{
		if($target === 'method')
		{
			return [strtoupper($facts->method)];
		}
		if($target === 'path')
		{
			return [$facts->path()];
		}
		[$prefix, $name] = explode(':', $target, 2) + [1 => ''];
		$header = strtolower($name);
		
		return match($prefix)
		{
			'query' => self::pick($facts->query, $name),
			'request' => [...self::pick($facts->query, $name), ...self::pick($facts->form, $name)],
			'body' => self::pick($facts->form, $name),
			'json' => self::pickJson($facts->json(), $name),
			'cookie' => self::pick($facts->cookies, $name),
			'header' => isset($facts->headers[$header]) ? [(string)$facts->headers[$header]] : [],
			'file' => self::pick($facts->files, $name),
			'query-keys' => self::keys($facts->query, $name),
			'body-keys' => self::keys($facts->form, $name),
			default => [],
		};
	}
	
	/**
	 * A bag's values at a name: `a[b][c]` walks nested arrays, a segment
	 * ending in `*` (or empty, `a[]`) takes every key with that prefix; an
	 * array reached gives every leaf
	 *
	 * @return list<string>
	 */
	public static function pick(
		array $bag,
		string $name,
	): array
	{
		$values = [];
		foreach(self::walk([$bag], self::segments($name)) as $node)
		{
			self::leaves($node, $values);
		}
		
		return $values;
	}
	
	/**
	 * The keys of the arrays at a name ('' = the bag's own) — a key is input too
	 *
	 * @return list<string>
	 */
	public static function keys(
		array $bag,
		string $name,
	): array
	{
		$keys = [];
		foreach(self::walk([$bag], self::segments($name)) as $node)
		{
			if(is_array($node))
			{
				foreach(array_keys($node) as $key)
				{
					$keys[] = (string)$key;
				}
			}
		}
		
		return $keys;
	}
	
	/**
	 * A JSON body's values at a dot path, `*` for any key or element
	 *
	 * @return list<string>
	 */
	public static function pickJson(
		mixed $json,
		string $path,
	): array
	{
		if(is_array($json) === false || $path === '')
		{
			return [];
		}
		$values = [];
		foreach(self::walk([$json], explode('.', $path)) as $node)
		{
			self::leaves($node, $values);
		}
		
		return $values;
	}
	
	/** `a[b][c]` as [a, b, c]; '' as none */
	public static function segments(
		string $name,
	): array
	{
		if($name === '')
		{
			return [];
		}
		$at = strpos($name, '[');
		if($at === false)
		{
			return [$name];
		}
		preg_match_all('~\[([^\]]*)\]~', substr($name, $at), $brackets);
		
		return [substr($name, 0, $at), ...$brackets[1]];
	}
	
	/** the nodes the segments reach from the given ones; a segment ending in `*`, or empty, takes every key with that prefix */
	protected static function walk(
		array $nodes,
		array $segments,
	): array
	{
		foreach($segments as $segment)
		{
			$segment = (string)$segment;
			$next = [];
			foreach($nodes as $node)
			{
				if(is_array($node) === false)
				{
					continue;
				}
				if($segment === '' || str_ends_with($segment, '*'))
				{
					$prefix = rtrim($segment, '*');
					foreach($node as $key => $child)
					{
						if(str_starts_with((string)$key, $prefix))
						{
							$next[] = $child;
						}
					}
				}
				else if(array_key_exists($segment, $node))
				{
					$next[] = $node[$segment];
				}
			}
			$nodes = $next;
		}
		
		return $nodes;
	}
	
	/** every scalar under a node, as the string a test reads */
	protected static function leaves(
		mixed $node,
		array &$values,
	): void
	{
		if(is_array($node))
		{
			foreach($node as $child)
			{
				self::leaves($child, $values);
			}
			
			return;
		}
		$values[] = match(true)
		{
			$node === true => '1',
			$node === false, $node === null => '',
			is_scalar($node) => (string)$node,
			default => '',
		};
	}
	
	/** a value through the decode steps; null when a step cannot read it (base64 that is not) */
	public static function decoded(
		string $value,
		array $steps,
	): ?string
	{
		foreach($steps as $step)
		{
			if($step === 'url')
			{
				$value = rawurldecode($value);
			}
			else if($step === 'base64')
			{
				$bytes = base64_decode($value, true);
				if($bytes === false)
				{
					return null;
				}
				$value = $bytes;
			}
		}
		
		return $value;
	}
	
	/** one value under one test — the literal ops fold ASCII, as a match rule's do */
	public static function passes(
		string $test,
		string $value,
		array $condition,
	): bool
	{
		$ci = ($condition['ci'] ?? true) === true;
		$expected = $condition['value'] ?? '';
		$text = is_string($expected) ? $expected : '';
		
		return match($test)
		{
			'equals' => $ci ? strcasecmp($text, $value) === 0 : $text === $value,
			'in' => self::among($value, (array)$expected, $ci),
			'not_in' => self::among($value, (array)$expected, $ci) === false,
			'prefix' => self::match(['op' => Ruleset::OP_PREFIX, 'value' => $text, 'ci' => $ci], $value) !== null,
			'suffix' => strlen($value) >= strlen($text)
				&& ($ci ? strcasecmp(substr($value, strlen($value) - strlen($text)), $text) === 0 : str_ends_with($value, $text)),
			'contains' => self::match(['op' => Ruleset::OP_CONTAINS, 'value' => $text, 'ci' => $ci], $value) !== null,
			'regex' => self::match(['op' => Ruleset::OP_REGEX, 'value' => $text, 'ci' => $ci], $value) !== null,
			'numeric' => preg_match('~^[+-]?[0-9]+$~D', $value) === 1,
			'not_numeric' => preg_match('~^[+-]?[0-9]+$~D', $value) !== 1,
			'longer_than' => is_int($expected) && strlen($value) > $expected,
			default => false,
		};
	}
	
	/** a value in a list, ASCII case folded or not */
	protected static function among(
		string $value,
		array $list,
		bool $ci,
	): bool
	{
		foreach($list as $item)
		{
			if(is_string($item) && ($ci ? strcasecmp($item, $value) === 0 : $item === $value))
			{
				return true;
			}
		}
		
		return false;
	}
	
	/**
	 * One rule over one subject: the fragment that matched, or null. The
	 * literal ops fold ASCII only; regex runs as `~…~D[i]`, never `u`; a
	 * pattern the engine refuses matches nothing
	 */
	public static function match(
		array $rule,
		string $subject,
	): ?string
	{
		$value = (string)($rule['value'] ?? '');
		$ci = ($rule['ci'] ?? true) === true;
		switch($rule['op'] ?? '')
		{
			case Ruleset::OP_EQUALS:
				return ($ci ? strcasecmp($value, $subject) === 0 : $value === $subject) ? $subject : null;
			case Ruleset::OP_PREFIX:
				$hit = $ci ? strlen($subject) >= strlen($value) && strncasecmp($value, $subject, strlen($value)) === 0 : str_starts_with($subject, $value);
				
				return $hit ? substr($subject, 0, strlen($value)) : null;
			case Ruleset::OP_CONTAINS:
				$at = $ci ? stripos($subject, $value) : strpos($subject, $value);
				
				return $at === false ? null : substr($subject, $at, strlen($value));
			case Ruleset::OP_CIDR:
				return self::inCidr($value, $subject) ? $subject : null;
			case Ruleset::OP_REGEX:
				$pattern = '~' . (string)preg_replace('#(?<!\\\\)((?:\\\\\\\\)*)~#', '$1\\~', $value) . '~D' . ($ci ? 'i' : '');
				
				return @preg_match($pattern, $subject, $m) === 1 ? (string)$m[0] : null;
			default:
				return null;
		}
	}
	
	/** an address in a prefix (`10.0.0.0/8`, `2001:db8::/32`) or equal to a bare one — v4 and v6, never a name */
	public static function inCidr(
		string $cidr,
		string $address,
	): bool
	{
		$cidr = trim($cidr);
		$slash = strpos($cidr, '/');
		$prefix = @inet_pton($slash === false ? $cidr : substr($cidr, 0, $slash));
		$packed = @inet_pton(trim($address));
		if($prefix === false || $packed === false || strlen($packed) !== strlen($prefix))
		{
			return false;
		}
		$max = strlen($prefix) * 8;
		$bits = $max;
		if($slash !== false)
		{
			$given = substr($cidr, $slash + 1);
			if(preg_match('~^\d{1,3}$~', $given) !== 1 || (int)$given > $max)
			{
				return false;
			}
			$bits = (int)$given;
		}
		for($i = 0, $n = strlen($packed); $i < $n && $bits > 0; $i++, $bits -= 8)
		{
			$mask = $bits >= 8 ? 0xFF : (0xFF << (8 - $bits)) & 0xFF;
			if((ord($packed[$i]) & $mask) !== (ord($prefix[$i]) & $mask))
			{
				return false;
			}
		}
		
		return true;
	}
	
	/** the default transport: curl when it is there, streams otherwise; never throws */
	protected function transport(
		string $url,
		array $headers,
		int $timeoutMs,
	): array
	{
		try
		{
			if(function_exists('curl_init'))
			{
				$curl = curl_init();
				curl_setopt_array($curl, [
					CURLOPT_URL => $url,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_HEADER => true,
					CURLOPT_HTTPHEADER => $headers,
					CURLOPT_USERAGENT => self::USER_AGENT,
					CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
					CURLOPT_TIMEOUT_MS => $timeoutMs,
					CURLOPT_NOSIGNAL => true,
				]);
				$raw = curl_exec($curl);
				// no curl_close(): a no-op since PHP 8.0 and deprecated from 8.5, where
				// a host that throws on deprecations turned every pull into status 0
				$status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
				if(is_string($raw) === false)
				{
					return ['status' => 0, 'headers' => [], 'body' => ''];
				}
				
				return ['status' => $status] + self::split($raw);
			}
			$context = stream_context_create(['http' => [
				'method' => 'GET',
				'header' => implode("\r\n", $headers) . "\r\nUser-Agent: " . self::USER_AGENT,
				'timeout' => $timeoutMs / 1000,
				'ignore_errors' => true,
			]]);
			$body = @file_get_contents($url, false, $context);
			$status = 0;
			$parsed = [];
			// http_get_last_response_headers() from 8.4; the predefined
			// $http_response_header, deprecated from 8.5 and gone in 9, before it
			$lines = function_exists('http_get_last_response_headers')
				? (http_get_last_response_headers() ?? [])
				: ($http_response_header ?? []);
			foreach($lines as $line)
			{
				if(preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m) === 1)
				{
					$status = (int)$m[1];
				}
				elseif(str_contains($line, ':'))
				{
					[$name, $value] = explode(':', $line, 2);
					$parsed[strtolower(trim($name))] = trim($value);
				}
			}
			
			return ['status' => $status, 'headers' => $parsed, 'body' => is_string($body) ? $body : ''];
		}
		catch(Throwable)
		{
			return ['status' => 0, 'headers' => [], 'body' => ''];
		}
	}
	
	/** a raw HTTP response (with CURLOPT_HEADER) into headers (lowercase names) and body, past any 1xx block */
	public static function split(
		string $raw,
	): array
	{
		$headers = [];
		while(true)
		{
			$at = strpos($raw, "\r\n\r\n");
			if($at === false)
			{
				return ['headers' => $headers, 'body' => $raw];
			}
			$block = substr($raw, 0, $at);
			$raw = substr($raw, $at + 4);
			$lines = explode("\r\n", $block);
			$statusLine = $lines[0] ?? '';
			foreach($lines as $line)
			{
				if(str_contains($line, ':'))
				{
					[$name, $value] = explode(':', $line, 2);
					$headers[strtolower(trim($name))] = trim($value);
				}
			}
			if(preg_match('~^HTTP/\S+\s+1\d\d~', $statusLine) !== 1)
			{
				return ['headers' => $headers, 'body' => $raw];
			}
		}
	}
	
	protected function log(
		string $line,
	): void
	{
		if($this->log !== null)
		{
			try
			{
				($this->log)('[shield] ' . $line);
			}
			catch(Throwable)
			{
			}
		}
	}
}
