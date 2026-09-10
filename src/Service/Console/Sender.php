<?php
declare(strict_types=1);

namespace Ovos\Service\Console;

use Ovos\ArrayObject;
use Ovos\Client;
use Ovos\Container\ArrayObject as InjectArrayObject;
use Ovos\Container\Inject;
use Ovos\Exception\NotFoundException;
use Ovos\Exception\Priority;
use Ovos\Http\Trace;
use Ovos\Service;
use Ovos\Service\Auth;
use Ovos\Service\Events;
use Ovos\Service\Session;
use Ovos\Service\Logger;
use ErrorException;
use SplObjectStorage;
use Throwable;

use function apcu_enabled;
use function apcu_inc;
use function array_replace;
use function array_values;
use function count;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function explode;
use function file_get_contents;
use function function_exists;
use function http_response_code;
use function in_array;
use function intdiv;
use function is_int;
use function is_readable;
use function is_string;
use function json_encode;
use function max;
use function mb_strlen;
use function mb_substr;
use function min;
use function preg_match;
use function rtrim;
use function str_starts_with;
use function strpos;
use function substr;
use function time;
use function trim;

use const CURLINFO_RESPONSE_CODE;
use const CURLOPT_CONNECTTIMEOUT_MS;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_NOSIGNAL;
use const CURLOPT_POST;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT_MS;
use const DIRECTORY_SEPARATOR;
use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_PARTIAL_OUTPUT_ON_ERROR;

/**
 * Reports collected errors to a central ovos/console instance.
 *
 * Fire-and-forget by contract: every public method swallows all
 * failures and the single HTTP call happens once per request from
 * Application::handleShutdown() with a hard timeout — the console
 * must never break or noticeably slow the host application.
 *
 * Project setup (environments.yml):
 *
 *   console:
 *     enabled: yes
 *     url: https://console.example      # instance base URL
 *     key: !ENV CONSOLE[KEY]            # project api_key
 *     log_level: 5                      # send priority <= this (0-7)
 *     timeout_ms: 1000
 *     release: !ENV CONSOLE[RELEASE]    # optional deploy label (git sha, svn rev, …); EMPTY =
 *                                       # the .release stamp beside .env is reported (php cli.php
 *                                       # release stamp, ovos/php-module-system) — a value here wins
 *     environment: staging              # optional deployment stage, sent verbatim. UNSET, the
 *                                       # app's own .env ENV is sent (production included — the
 *                                       # console badges only non-production values)
 *     rollups: no                       # OPT-IN: per-minute traffic counters (requests,
 *                                       # status/method/route/authed) accumulated in APCu and
 *                                       # POSTed to /api/v1/ingest/rollup once per minute —
 *                                       # the console's denominator layer (see Rollup). Needs
 *                                       # url+key AND rollups_enabled on the console project;
 *                                       # no APCu means a silent no-op. A text/event-stream
 *                                       # response counts as a request but carries no duration.
 *     otlp_url: ''                      # OPTIONAL: an OpenTelemetry Collector's OTLP/HTTP
 *                                       # logs endpoint VERBATIM (http://collector:4318/v1/logs).
 *                                       # Set -> the batch goes there as OTLP/JSON instead of the
 *                                       # direct ingest; the collector holds the console key in
 *                                       # its own exporter, so url/key become optional here. Never
 *                                       # combine with url+key when the collector exports back to
 *                                       # the console — errors would double-report.
 *
 * plus "- Console\Sender" in system.services.http and .cli lists.
 *
 * The deploy step tells the console a release shipped the minute it does:
 * `$sender->announceRelease()` (SENDER.md §7) — the label the events carry,
 * or the one the caller names, with an optional moment, ref and source.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Sender extends Service
{
	/**
	 * The deploy stamp beside .env — per deployment, machine-written, never
	 * committed (`php cli.php release stamp`); read when console.release is empty
	 */
	public const string RELEASE_FILE = '.release';
	
	/** the label's cap — the console's column and its own mb_substr agree on it */
	public const int RELEASE_MAX = 64;
	
	public const string SYMBOL = 'consoleSender';
	
	/**
	 * Cap on explicitly captured payloads per flush cycle — an error loop
	 * in a long-running CLI process must not grow the queue without bound
	 * (the console ingest caps batches server-side anyway). The flush-time
	 * Events merge gets its own headroom up to twice this, so uncaught
	 * errors are never starved by a queue already filled with captures.
	 */
	public const int QUEUE_MAX = 100;
	
	/**
	 * The closed kind vocabulary for type=security events (reportRefusal) —
	 * mirrors the console's App::SECURITY_KINDS. A kind outside this list is
	 * a no-op here and refused server-side; the list only ever grows in a
	 * deliberate two-sided change. auth_success is for exactly one case: a
	 * login that SUCCEEDED after recent failures for the same account or
	 * address — never report clean logins.
	 */
	public const array SECURITY_KINDS = [
		'auth_failure',
		'auth_success',
		'csrf_reject',
		'permission_denied',
		'rate_limited',
		'validation_refused',
		'privileged_action',
	];
	
	/**
	 * Rolling cap on security events across the FPM pool (APCu minute
	 * counter): a credential-stuffing wave is thousands of auth_failures a
	 * minute, and the reporter must not become the flood. Without APCu the
	 * per-request QUEUE_MAX still bounds each batch.
	 */
	public const int SECURITY_MAX_PER_MINUTE = 60;
	
	protected ?ArrayObject $config;
	
	/**
	 * Queued payloads, append-only.
	 */
	protected array $queue = [];
	
	/**
	 * Throwables already queued, so the Events service and the Logger hook
	 * never double-report one object. An SplObjectStorage (not spl_object_id
	 * keys): it HOLDS the reference, so a queued throwable cannot be freed
	 * and have its recycled object id collide with a later, different one —
	 * id reuse silently overwrote queued events.
	 */
	protected ?SplObjectStorage $seen = null;
	
	protected static bool $flushing = false;
	
	public function __construct(
		#[Inject('config')]
		#[InjectArrayObject('console')]
		?ArrayObject $config,
	)
	{
		$this->config = $config;
	}
	
	public function isEnabled(): bool
	{
		if($this->config === null || $this->config->enabled !== true)
		{
			return false;
		}
		
		// either transport suffices: the direct ingest (url + key) or an
		// OTLP collector endpoint (which holds the console key itself)
		return ((string)$this->config->url !== '' && (string)$this->config->key !== '')
			|| $this->getOtlpUrl() !== '';
	}
	
	/**
	 * The collector's OTLP/HTTP logs endpoint — set, the batch is exported
	 * there as OTLP/JSON (Otlp::request) instead of the direct ingest
	 */
	public function getOtlpUrl(): string
	{
		return (string)($this->config?->otlp_url ?? '');
	}
	
	public function getLogLevel(): int
	{
		return (int)($this->config?->log_level ?? Priority::NOTICE);
	}
	
	/**
	 * Whether to attach a few source lines around each throw location
	 * (console.source_context, default on). Off means the source files
	 * are never even read — for projects that must not ship code lines
	 * off-box.
	 */
	public function capturesSource(): bool
	{
		return ($this->config?->source_context ?? true) !== false;
	}
	
	/**
	 * Start building an arbitrary event bound to this sender. A thrown
	 * exception is optional — set a message, extras and context overrides, then
	 * call Event::capture(). Reachable through the container from anywhere:
	 * container()->get(Sender::SYMBOL)->event() (or services()->consoleSender).
	 */
	public function event(): Event
	{
		return new Event($this);
	}
	
	/**
	 * Queue an arbitrary event. The single HTTP flush still happens once per
	 * request from Application::handleShutdown().
	 */
	public function capture(
		Event $event,
	): static
	{
		if($this->isEnabled() === false)
		{
			return $this;
		}
		
		try
		{
			$this->enqueue($event, self::QUEUE_MAX);
		}
		catch(Throwable)
		{
			// never break the host application
		}
		
		return $this;
	}
	
	public function captureException(
		Throwable $event,
		array $extra = [],
		?int $priority = null,
	): static
	{
		return $this->capture(
			$this->event()
				->exception($event)
				->extras($extra)
				->priority($priority),
		);
	}
	
	public function captureMessage(
		string $message,
		int $priority = Priority::NOTICE,
		array $extra = [],
	): static
	{
		return $this->capture(
			$this->event()
				->message($message)
				->priority($priority)
				->extras($extra),
		);
	}
	
	/**
	 * Reports a not-found access event as a type=404 report (priority 6, INFO).
	 * The console groups these apart from application errors, never turns them
	 * into issues, and its per-project report_404 switch decides acceptance.
	 * No-op unless console.report_404 is enabled here. The path defaults to the
	 * current request URI and the query string is dropped so distinct probes
	 * stay distinct while one hammered path folds together.
	 */
	public function capture404(
		string $path = '',
		array $extra = [],
	): static
	{
		if($this->reports404() === false || $this->isEnabled() === false)
		{
			return $this;
		}
		
		try
		{
			if(count($this->queue) < self::QUEUE_MAX)
			{
				$this->queue[] = $this->payload404($path, $extra);
			}
		}
		catch(Throwable)
		{
			// never break the host application
		}
		
		return $this;
	}
	
	/**
	 * Whether not-found access events are reported as type=404 (opt-in per app)
	 */
	protected function reports404(): bool
	{
		return $this->config?->report_404 === true;
	}
	
	/**
	 * Reports a refusal or audit line as a type=security event (priority 6,
	 * INFO — the console pins it there regardless). The console groups these
	 * apart from application errors, never turns them into issues or alerts
	 * by default, and gates them by its per-project security_events switch —
	 * NOT by accept_priority, so a project tuned stricter than INFO still
	 * receives them. Placing this call is the app-side opt-in; there is no
	 * config switch here on purpose.
	 *
	 * $kind must come from SECURITY_KINDS (anything else is a silent no-op —
	 * the console refuses unknown kinds wholesale, so sending one would only
	 * waste the request). $message is the human line and travels into an
	 * INDEXED, displayed field: mask identifiers yourself — maskName() for
	 * usernames — and never include a credential; the server scrub is a
	 * backstop, not permission.
	 *
	 *   $sender->reportRefusal('auth_failure',
	 *       'login failed for ' . Sender::maskName($username));
	 *
	 * $context is the per-event word on the request context, merged over the
	 * base the flush builds (array_replace — the caller wins). Its case is
	 * the ACCOUNT: name it as `userId` (the internal id, never the login
	 * name) wherever the application knows who — the login that just
	 * succeeded is reported before the session holds the user, so the Auth
	 * service cannot supply it there. The console groups a security event by
	 * kind and account, never by its masked line.
	 *
	 *   $sender->reportRefusal('auth_success',
	 *       'login succeeded for ' . Sender::maskName($username) . ' after 3 recent failures',
	 *       ['failures' => 3], ['userId' => (string)$user->id]);
	 */
	public function reportRefusal(
		string $kind,
		string $message = '',
		array $extra = [],
		array $context = [],
	): static
	{
		if($this->isEnabled() === false
			|| in_array($kind, self::SECURITY_KINDS, true) === false
			|| $this->allowSecurity() === false)
		{
			return $this;
		}
		
		try
		{
			if(count($this->queue) < self::QUEUE_MAX)
			{
				$this->queue[] = $this->payloadSecurity($kind, $message, $extra, $context);
			}
		}
		catch(Throwable)
		{
			// never break the host application
		}
		
		return $this;
	}
	
	/**
	 * A username reduced to every fourth character, the rest starred (bob ->
	 * b**, marcin -> m***i*) — the same mask every scrub path applies, offered
	 * here so a reportRefusal call site is a one-liner. The mask is as long as
	 * the value it replaced, and past Logger::MASK_MAX it states the real
	 * length instead ("[200]"); see Logger::maskName(), which this mirrors.
	 */
	public static function maskName(
		string $value,
	): string
	{
		if($value === ''
			|| preg_match(Logger::MASKED_CUT_PATTERN, $value) === 1)
		{
			return $value;
		}
		
		$length = mb_strlen($value);
		$cut = min($length, Logger::MASK_MAX);
		$masked = '';
		for($index = 0; $index < $cut; $index++)
		{
			$masked.= $index % Logger::MASK_GROUP === 0
				? mb_substr($value, $index, 1)
				: '*';
		}
		
		return $length > $cut ? $masked . '[' . $length . ']' : $masked;
	}
	
	/**
	 * A type=security payload: the KIND as the event's className (the field
	 * the console indexes, filters and fingerprints by), the human line as
	 * the message — falling back to the kind itself, so a call without a
	 * message still names its event — and the caller's context overrides
	 * (the account), which the flush merges over the base it builds
	 */
	protected function payloadSecurity(
		string $kind,
		string $message,
		array $extra,
		array $context = [],
	): array
	{
		$message = $message === '' ? $kind : mb_substr($message, 0, 512);
		
		$payload = Payload::fromMessage($message, Priority::INFO, $extra);
		$payload['type'] = 'security';
		$payload['kind'] = 'security';
		$payload['events'] = [[
			'message' => $message,
			'className' => $kind,
			'file' => '',
			'line' => 0,
			'backtrace' => '',
			'previous' => false,
		]];
		if($context !== [])
		{
			$payload['context'] = $context;
		}
		
		return $payload;
	}
	
	/**
	 * The rolling pool-wide cap (APCu minute counter, created with a TTL so
	 * quiet minutes leave nothing behind). No APCu means no cross-request
	 * cap — same standing as 404 reporting, where QUEUE_MAX per request is
	 * the only bound.
	 */
	protected function allowSecurity(): bool
	{
		if(function_exists('apcu_enabled') === false || apcu_enabled() === false)
		{
			return true;
		}
		
		$ok = false;
		$count = apcu_inc(
			'ovos:console:security:' . intdiv(time(), 60), 1, $ok, 120);
		
		return $count === false || $count <= self::SECURITY_MAX_PER_MINUTE;
	}
	
	/**
	 * Whether the throwable records an access event — request noise caused by
	 * the client, not an application error. A routing miss (unknown
	 * controller/action) counts when the app opted into 404 reporting; covers
	 * the Events-service merge (where framework 404s arrive) and an explicit
	 * NotFoundException capture alike. A request-startup refusal — PHP dropping
	 * a malformed multipart body before any userland code ran, the signature of
	 * upload-exploit scanners — always counts: the application never had a say,
	 * so it must not be blamed. PHP emits the "PHP Request Startup: " prefix
	 * only for errors raised during that phase; handleShutdown() delivers them
	 * here as the ErrorException built from error_get_last().
	 */
	protected function isAccessEvent(
		?Throwable $throwable,
	): bool
	{
		if($throwable instanceof NotFoundException)
		{
			return $this->reports404();
		}
		
		return $throwable instanceof ErrorException
			&& str_starts_with($throwable->getMessage(), 'PHP Request Startup: ');
	}
	
	/**
	 * A type=404 payload (INFO priority). With a throwable its own message is
	 * preserved — the router's subtype detail ("File not found: …", a controller
	 * miss) — instead of being flattened; without one the request path becomes
	 * the message. The query is dropped either way so 404 fingerprints stay
	 * stable (distinct paths distinct, one path's repeats fold). The message is
	 * attacker-influenced text: display surfaces must escape it (the SPA does so
	 * via Lit; server-rendered views via View::escape).
	 */
	protected function payload404(
		string $path = '',
		array $extra = [],
		?Throwable $throwable = null,
	): array
	{
		if($throwable !== null)
		{
			// preserve the exact message the router threw ("File not found: …",
			// a controller miss, and so on) instead of flattening every 404 to
			// the same text — that subtype detail is the point of reporting them
			$message = $throwable->getMessage();
		}
		else
		{
			if($path === '')
			{
				$path = (string)($_SERVER['REQUEST_URI'] ?? '');
			}
			
			// drop the query so distinct probes stay distinct while one hammered
			// path folds together
			$mark = strpos($path, '?');
			if($mark !== false)
			{
				$path = substr($path, 0, $mark);
			}
			
			$message = '404 Not Found: ' . $path;
		}
		
		$payload = Payload::fromMessage(
			mb_substr($message, 0, 512),
			Priority::INFO,
			$extra,
		);
		$payload['type'] = '404';
		$payload['kind'] = 'not_found';
		
		return $payload;
	}
	
	/**
	 * Queues one event, deduping throwables by live object identity.
	 * offsetExists/offsetSet, not contains/attach — the aliases are
	 * deprecated in PHP 8.5 and the promoted deprecation would land in the
	 * capture catch, silently dropping the event. The storage HOLDS the
	 * reference, so a queued throwable cannot be freed and have its recycled
	 * object id collide with a later, different one. An event without a
	 * throwable cannot be deduped by identity — it is queued up to the limit.
	 */
	protected function enqueue(
		Event $event,
		int $limit,
	): void
	{
		$this->seen ??= new SplObjectStorage;
		$throwable = $event->getThrowable();
		
		if($throwable !== null && $this->seen->offsetExists($throwable))
		{
			return;
		}
		
		if(count($this->queue) >= $limit)
		{
			return;
		}
		
		// build the payload BEFORE marking the event as seen: if construction
		// throws, a pre-marked event would count as already queued for the
		// rest of the request and never get another chance
		$payload = $this->isAccessEvent($throwable)
			? $this->payload404('', [], $throwable)
			: $event->toPayload();
		
		if($throwable !== null)
		{
			$this->seen->offsetSet($throwable);
		}
		
		$this->queue[] = $payload;
	}
	
	/**
	 * Builds and posts the batch. Application::handleShutdown() invokes this
	 * explicitly after the response and the post-response callbacks — for
	 * EVERY request, so uncaught errors that reached only the Events service
	 * are reported even when nothing was captured through the sender.
	 */
	public function flush(): void
	{
		// re-entrancy guard: the outer call owns the queue, leave it intact
		if(self::$flushing)
		{
			return;
		}
		
		// the traffic rollup rides the same shutdown hook: one apcu_inc set
		// per request, a POST only when a minute boundary was crossed — and
		// the same silence contract, so it runs before anything can bail
		try
		{
			(new Rollup($this->config))->observe($this->app);
		}
		catch(Throwable)
		{
			// never break the host application
		}
		
		// disabled: drop anything queued so it cannot pile up in a
		// long-lived service or bleed into a later request (the Events
		// service is drained by handleShutdown() after all post-response
		// consumers ran — not here, where it would starve the profiler)
		if($this->isEnabled() === false)
		{
			$this->queue = [];
			$this->seen = null;
			
			return;
		}
		
		self::$flushing = true;
		
		try
		{
			$errors = $this->buildBatch();
			
			if($errors !== [])
			{
				$this->send((string)json_encode(
					$this->getOtlpUrl() !== '' ? Otlp::request($errors) : $errors,
					JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
			}
		}
		catch(Throwable)
		{
			// silence is the contract
		}
		finally
		{
			$this->queue = [];
			$this->seen = null;
			self::$flushing = false;
		}
	}
	
	/**
	 * Merges the Events service's uncaught throwables into the queue, then
	 * filters by log level and decorates each surviving payload with the
	 * request/CLI context and the deploy label — the finished v1 batch.
	 *
	 * @return array[]
	 */
	protected function buildBatch(): array
	{
		// merge uncaught errors collected by the Events service — read
		// only: other post-response consumers (the profiler stream) still
		// need the events; the Application drains them after the chain.
		// The merge gets headroom past QUEUE_MAX so uncaught errors are
		// not starved by a queue already filled with explicit captures,
		// while an error loop stays bounded; enqueue() dedupes via $seen.
		$events = $this->container->get(Events::SYMBOL);
		foreach($events as $event)
		{
			if($event instanceof Throwable === false)
			{
				continue;
			}
			
			try
			{
				$this->enqueue($this->event()->exception($event), self::QUEUE_MAX * 2);
			}
			catch(Throwable)
			{
				// one unqueueable event must not abort the whole flush —
				// the batch built so far (and the queue) still goes out
			}
		}
		
		$logLevel = $this->getLogLevel();
		$context = null;
		// the shared scrub patterns live in the Logger service
		$logger = $this->getLogger();
		
		// the deploy label (git sha, svn revision, any string): console.release,
		// else the .release stamp — constant across the batch, read once
		$release = self::currentRelease($this->config?->release ?? null);
		// deployment stage: an explicit console.environment wins, otherwise
		// the app's own env name — production included (the console stores
		// and filters it, but only badges anything else)
		$environment = $this->environment();
		
		$errors = [];
		foreach($this->queue as $payload)
		{
			// a 404 access event and a security event ride the INFO band but
			// are KINDS, not severities — the log_level gate (a severity
			// filter) must not drop them (security used to fall through it:
			// a log_level below INFO silently lost every reportRefusal)
			if(($payload['kind'] ?? 'error') === 'error'
				&& $payload['priority'] > $logLevel)
			{
				continue;
			}
			
			$context ??= $this->buildContext($logger);
			
			// the console's three axes (ovos/console 2026-09): what ran the
			// code, how it was entered, what the record IS. `type` is the
			// legacy slot older consoles read (http/cli/404/security) and
			// stays beside them until every console has updated
			$payload['runtime'] = 'php';
			$payload['entry'] = $context['entry'];
			$payload['kind'] ??= 'error';
			// respect a type the payload already carries (404, security);
			// otherwise take the request-derived type (http/cli)
			$payload['type'] ??= $context['type'];
			// per-event context overrides win over the auto-built base;
			// extras are scrubbed like request variables (secrets, e-mails,
			// usernames) — the WP sender and the JS clients do the same
			$payload['context'] = array_replace($context['context'],
					$payload['context'] ?? [])
				+ ['extra' => $logger->remove($payload['extra'])];
			unset($payload['extra']);
			
			if($release !== '')
			{
				$payload['release'] = $release;
			}
			
			if($environment !== '')
			{
				$payload['environment'] = $environment;
			}
			
			$errors[] = $payload;
		}
		
		return $errors;
	}
	
	/**
	 * @return array{type: string, context: array}
	 */
	protected function buildContext(
		Logger $logger,
	): array
	{
		$isCli = $this->app->isInterfaceCli();
		
		$context = [
			// dir is a TAG on the console side — the rtrim keeps the value
			// separator-free at the end so every sender agrees on one form;
			// BASE_DIR itself always ends with DIRECTORY_SEPARATOR
			'dir' => rtrim(BASE_DIR, DIRECTORY_SEPARATOR),
			// correlates every error of this request/run in the console —
			// across services when an inbound traceparent is propagated
			'traceId' => Trace::id(),
		];
		
		if($isCli)
		{
			$context['host'] = (string)($this->app->getConfig()->system->domain ?? '');
			$context['args'] = isset($_SERVER['argv'])
				? $logger->removeFromArgs(array_values((array)$_SERVER['argv']))
				: [];
		}
		else
		{
			$context['host'] = (string)($_SERVER['HTTP_HOST'] ?? '');
			// secrets and e-mails travel in query strings too — scrub the
			// url copies the same way request.get is scrubbed
			$context['uri'] = $logger->removeFromUrl((string)($_SERVER['REQUEST_URI'] ?? ''));
			$context['method'] = (string)($_SERVER['REQUEST_METHOD'] ?? '');
			$context['referer'] = $logger->removeFromUrl((string)($_SERVER['HTTP_REFERER'] ?? ''));
			$context['ip'] = (string)Client::getIp();
			$context['ua'] = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
			
			// the status the response ENDED with (console contract: context.status,
			// docs/SENDER.md). This runs at the shutdown flush, after the response
			// went out, so the SAPI has the final word: 500 for an uncaught
			// exception's error page, 404 for a not-found route, 200 for an
			// exception caught and answered. Where nothing answers — the CLI SAPI —
			// no key: the console never guesses one, and neither does the sender
			$status = $this->responseStatus();
			if($status !== null)
			{
				$context['status'] = $status;
			}
			
			// handler-agnostic: the native session machinery (and its
			// session_id()) never runs under the json handler
			$session = $this->app->getServices()->session;
			if($session instanceof Session
				&& ($sessionId = $session->getId()) !== null)
			{
				$context['sessionId'] = $sessionId;
			}
			
			// the signed-in account, when the application put its user into
			// the Auth service: context.userId, the console's indexed user_id
			// — who an error happened to, and for a security event the
			// account that IS the case (ovos/console docs/plans/security-
			// event-identity.md). The internal id, never the login name
			$auth = $this->app->getServices()->auth;
			if($auth instanceof Auth && $auth->hasUser() && isset($auth->getUser()->id))
			{
				$context['userId'] = (string)$auth->getUser()->id;
			}
			
			$context['request'] = $this->buildRequest($logger);
		}
		
		return [
			'type' => $isCli ? 'cli' : 'http',
			'entry' => $isCli ? 'cli' : 'web',
			'context' => $context,
		];
	}
	
	/**
	 * http_response_code() as an int in the HTTP range, null otherwise (false
	 * under the CLI SAPI, or a value nothing would send) — its own method so
	 * a test can script what the SAPI would answer
	 */
	protected function responseStatus(): ?int
	{
		return self::statusOf(http_response_code());
	}
	
	/**
	 * What http_response_code() answered, as the context value or nothing:
	 * an int in the HTTP range 100..599 passes, false (no SAPI status) and
	 * anything outside the range does not
	 */
	public static function statusOf(
		mixed $status,
	): ?int
	{
		return is_int($status) && $status >= 100 && $status <= 599 ? $status : null;
	}
	
	/**
	 * The Logger service carries the shared scrub patterns; a bare instance
	 * (the scrub methods never touch its injected services) still scrubs
	 * fine when the container cannot deliver the service
	 */
	protected function getLogger(): Logger
	{
		try
		{
			return $this->container->get(Logger::SYMBOL);
		}
		catch(Throwable)
		{
			return new Logger;
		}
	}
	
	/**
	 * Request variables, redacted with the Logger patterns
	 * (the console scrubs again server-side as a backstop)
	 */
	protected function buildRequest(
		Logger $logger,
	): array
	{
		$request = [];
		
		try
		{
			if(!empty($_GET))
			{
				$request['get'] = $logger->remove($_GET);
			}
			if(!empty($_POST))
			{
				$request['post'] = $logger->remove($_POST);
			}
		}
		catch(Throwable)
		{
			// scrubbing failed — send without request variables
		}
		
		return $request;
	}
	
	protected function send(
		string $json,
	): void
	{
		$otlp = $this->getOtlpUrl();
		
		// OTLP mode posts to the collector endpoint verbatim, without the
		// console key — the collector authenticates via its own exporters
		$handle = curl_init($otlp !== ''
			? $otlp
			: rtrim((string)$this->config->url, '/') . '/api/v1/ingest');
		
		$headers = ['Content-Type: application/json'];
		if($otlp === '')
		{
			$headers[] = 'X-Console-Key: ' . (string)$this->config->key;
		}
		
		curl_setopt_array($handle, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $json,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_RETURNTRANSFER => true,
			// a libcurl without the threaded resolver times sub-second
			// timeouts via SIGALRM, which cannot do sub-second at all — it
			// refuses with errno 28 BEFORE even resolving, losing every
			// batch. NOSIGNAL switches to poll-based timing, where the
			// 300ms connect bound works; only the DNS phase itself is then
			// bounded by the system resolver instead of this option.
			CURLOPT_NOSIGNAL => true,
			CURLOPT_CONNECTTIMEOUT_MS => 300,
			CURLOPT_TIMEOUT_MS => (int)($this->config->timeout_ms ?? 1000),
		]);
		
		curl_exec($handle);
	}
	
	/**
	 * Tell the console a release shipped — the deploy step (SENDER.md §7):
	 * `POST /api/v1/ingest/release` with the project key. The label is the
	 * one the events carry (console.release, else the .release stamp) unless
	 * the caller names one; `at` (epoch seconds, ms or ISO 8601), `ref`,
	 * `source` and `environment` are optional. Synchronous — a deploy step
	 * wants the answer — and still best-effort: false, never an exception,
	 * when the sender is off, nothing is stamped or the console is out of
	 * reach. Direct ingest only: the OTLP collector has no release endpoint.
	 *
	 * @param array{at?: int|string, ref?: string, source?: string, environment?: string} $options
	 * @return bool whether the console accepted the announce (202)
	 */
	public function announceRelease(
		string $release = '',
		array $options = [],
	): bool
	{
		if($this->config === null || $this->config->enabled !== true
			|| (string)$this->config->url === '' || (string)$this->config->key === '')
		{
			return false;
		}
		
		try
		{
			$payload = self::releasePayload(
				$release !== '' ? $release : self::currentRelease($this->config->release ?? null),
				$options,
				$this->environment(),
			);
			if($payload === [])
			{
				return false;
			}
			$json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
			
			return $json !== false && $this->post('/api/v1/ingest/release', $json) === 202;
		}
		catch(Throwable)
		{
			return false;
		}
	}
	
	/**
	 * Pure: the announce body — the label's first line capped like the
	 * column, the source (the deploy tool; this library when unnamed), and
	 * the optional fields only when given; [] without a label, so a deploy
	 * step on an unstamped checkout announces nothing rather than an empty
	 * release
	 *
	 * @param array{at?: int|string, ref?: string, source?: string, environment?: string} $options
	 * @return array<string, int|string>
	 */
	public static function releasePayload(
		string $release,
		array $options,
		string $environment,
	): array
	{
		$label = self::firstLine($release);
		if($label === '')
		{
			return [];
		}
		
		$payload = [
			'release' => $label,
			'source' => mb_substr(trim((string)($options['source'] ?? 'php-library')), 0, 32),
		];
		$at = $options['at'] ?? null;
		if(is_int($at) || (is_string($at) && trim($at) !== ''))
		{
			$payload['at'] = is_int($at) ? $at : trim($at);
		}
		$ref = trim((string)($options['ref'] ?? ''));
		if($ref !== '')
		{
			$payload['ref'] = mb_substr($ref, 0, 128);
		}
		$stage = trim((string)($options['environment'] ?? $environment));
		if($stage !== '')
		{
			$payload['environment'] = mb_substr($stage, 0, 64);
		}
		
		return $payload;
	}
	
	/**
	 * The deployment stage the batch and the announce carry: an explicit
	 * console.environment wins, otherwise the app's own env name
	 */
	protected function environment(): string
	{
		$environment = (string)($this->config?->environment ?? '');
		if($environment === '')
		{
			$environment = $this->app->getEnv();
		}
		
		return mb_substr($environment, 0, 64);
	}
	
	/**
	 * One synchronous JSON POST to the console with the project key, the
	 * response code back (0 = no answer) — the announce's transport; the
	 * batch keeps send() and its fire-and-forget timing
	 */
	protected function post(
		string $path,
		string $json,
	): int
	{
		$handle = curl_init(rtrim((string)$this->config->url, '/') . $path);
		curl_setopt_array($handle, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $json,
			CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Console-Key: ' . (string)$this->config->key],
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_NOSIGNAL => true,
			CURLOPT_CONNECTTIMEOUT_MS => 1000,
			// a deploy step waits for the answer — longer than the batch's bound
			CURLOPT_TIMEOUT_MS => max(2000, (int)($this->config->timeout_ms ?? 1000)),
		]);
		curl_exec($handle);
		
		return (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
	}
	
	/**
	 * The deploy label the batch carries: the configured console.release when
	 * it is non-empty, else the first line of BASE_DIR/.release — the stamp
	 * `php cli.php release stamp` (ovos/php-module-system) writes on deploy.
	 * CONFIGURED WINS: a deployment that supplies a value knows something a
	 * generated stamp cannot, so the stamp is a fallback, never an override —
	 * which also means a placeholder like "dev" outranks it. Leave
	 * console.release EMPTY to let the stamp speak. Nothing anywhere is '',
	 * the behaviour every project had before the stamp existed.
	 */
	public static function currentRelease(
		mixed $configured,
	): string
	{
		return self::releaseLabel($configured, self::stamp(BASE_DIR . self::RELEASE_FILE));
	}
	
	/**
	 * Pure: the configured value when non-empty, else the stamp — first line,
	 * trimmed, capped at RELEASE_MAX like the column behind it
	 */
	public static function releaseLabel(
		mixed $configured,
		?string $stamp,
	): string
	{
		$value = self::firstLine(is_string($configured) ? $configured : '');
		
		return $value !== '' ? $value : self::firstLine($stamp ?? '');
	}
	
	/**
	 * The stamp file's contents, null when absent or unreadable — both mean
	 * "no label", and neither is worth a warning on the request that reads it
	 * (Events::handleError would turn one into a thrown ErrorException)
	 */
	public static function stamp(
		string $path,
	): ?string
	{
		if(is_readable($path) === false)
		{
			return null;
		}
		
		try
		{
			$contents = file_get_contents($path);
		}
		catch(Throwable)
		{
			return null;
		}
		
		return $contents === false ? null : $contents;
	}
	
	/**
	 * A stamp written by a shell redirect carries a trailing newline, a
	 * careless one the whole `git log` — the first line is the label
	 */
	protected static function firstLine(
		string $value,
	): string
	{
		return mb_substr(trim(explode("\n", trim($value))[0]), 0, self::RELEASE_MAX);
	}
}
