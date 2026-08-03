<?php
declare(strict_types=1);

namespace Ovos\Service\Console;

use Ovos\ArrayObject;
use Ovos\Client;
use Ovos\Container\ArrayObject as InjectArrayObject;
use Ovos\Container\Inject;
use Ovos\Exception\NotFoundException;
use Ovos\Http\Trace;
use Ovos\Service;
use Ovos\Service\Events;
use Ovos\Service\Session;
use Ovos\Service\Logger;
use SplObjectStorage;
use Throwable;

use function array_replace;
use function array_values;
use function count;
use function curl_exec;
use function curl_init;
use function curl_setopt_array;
use function defined;
use function json_encode;
use function mb_substr;
use function rtrim;
use function strpos;
use function substr;

use const CURLOPT_CONNECTTIMEOUT_MS;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_NOSIGNAL;
use const CURLOPT_POST;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT_MS;
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
 *     release: !ENV CONSOLE[RELEASE]    # optional deploy label (git sha, svn rev, …)
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
 * @author Marcin Gil <mg@ovos.at>
 */
class Sender extends Service
{
	public const string SYMBOL = 'consoleSender';
	
	/**
	 * Cap on explicitly captured payloads per flush cycle — an error loop
	 * in a long-running CLI process must not grow the queue without bound
	 * (the console ingest caps batches server-side anyway). The flush-time
	 * Events merge gets its own headroom up to twice this, so uncaught
	 * errors are never starved by a queue already filled with captures.
	 */
	public const int QUEUE_MAX = 100;
	
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
		
		// a routing miss (unknown controller/action) is an access event, not an
		// application error — report it as a type=404 when the app opts in; covers
		// the Events-service merge (where framework 404s arrive) and an explicit
		// NotFoundException capture alike.
		//
		// build the payload BEFORE marking the event as seen: if construction
		// throws, a pre-marked event would count as already queued for the
		// rest of the request and never get another chance
		$payload = $throwable instanceof NotFoundException && $this->reports404()
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
		
		// optional deploy label (git sha, svn revision, any string) —
		// constant across the batch, read once
		$release = mb_substr((string)($this->config?->release ?? ''), 0, 64);
		
		$errors = [];
		foreach($this->queue as $payload)
		{
			// a 404 access event rides the INFO band but is a KIND, not a
			// severity — the log_level gate (a severity filter) must not drop it
			if(($payload['type'] ?? '') !== '404'
				&& $payload['priority'] > $logLevel)
			{
				continue;
			}
			
			$context ??= $this->buildContext($logger);
			
			// respect a type the payload already carries (404); otherwise
			// take the request-derived type (http/cli)
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
			'dir' => defined('BASE_DIR') ? rtrim(BASE_DIR, '/\\') : '',
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
			
			// handler-agnostic: the native session machinery (and its
			// session_id()) never runs under the json handler
			$session = $this->app->getServices()->session;
			if($session instanceof Session
				&& ($sessionId = $session->getId()) !== null)
			{
				$context['sessionId'] = $sessionId;
			}
			
			$context['request'] = $this->buildRequest($logger);
		}
		
		return [
			'type' => $isCli ? 'cli' : 'http',
			'context' => $context,
		];
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
}
