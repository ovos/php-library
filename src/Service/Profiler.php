<?php
declare(strict_types=1);

namespace Ovos\Service;

use Ovos\ArrayObject;
use Ovos\Connections;
use Ovos\Console;
use Ovos\Container\ArrayObject as InjectArrayObject;
use Ovos\Container\Inject;
use Ovos\Pdo\Profiler\Reporter as QueriesReporter;
use Ovos\Redis\Profiler\Collector as RedisCollector;
use Ovos\Redis\Profiler\Reporter as RedisReporter;
use Ovos\Service;
use Ovos\Terminal;
use Ovos\Terminal\Highlighter;
use Redis as RedisClient;
use Throwable;

use function get_class;
use function getenv;
use function getmypid;
use function implode;
use function json_encode;
use function memory_get_peak_usage;
use function microtime;
use function preg_match;
use function round;
use function strlen;
use function substr;
use function uniqid;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_PARTIAL_OUTPUT_ON_ERROR;
use const PHP_EOL;

/**
 * Profiler
 *
 * Streams the per-request profile (queries, redis commands, console messages,
 * benchmark and errors) onto a redis stream keyed by session, so the profiler
 * panel and the /profiler/ surface can tail it live — including on XHR/JSON
 * requests, where the panel itself never renders.
 *
 * CLI runs (cron or manual) stream onto a shared 'cli' stream instead: a
 * start entry at boot, one live entry per Terminal::output() message (raw,
 * <color> tags intact) and a finish entry with duration/memory/errors.
 *
 * Dev-only and self-disabling: registers nothing unless both
 * profilers.enabled and profilers.stream.enabled are set.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Profiler extends Service
{
	public const string SYMBOL = 'profiler';
	
	/**
	 * Stream lifetime (seconds) when profilers.stream.ttl is not configured
	 */
	public const int TTL_DEFAULT = 3600;
	
	/**
	 * Cap for a single exception's rendered trace — one deep trace must not
	 * dominate the stream entry
	 */
	public const int TRACE_MAX_LENGTH = 8192;
	
	/**
	 * Suffix of the shared CLI stream key (key_prefix + 'cli') — CLI runs
	 * have no session, and every watcher sees all of them
	 */
	public const string CLI_KEY = 'cli';
	
	/**
	 * CLI stream cap when profilers.stream.cli_maxlen is not configured —
	 * message-grained entries need more room than per-request profiles
	 */
	public const int CLI_MAXLEN_DEFAULT = 1000;
	
	#[Inject('config')]
	#[InjectArrayObject('system', 'profilers')]
	protected ?ArrayObject $profilers = null;
	
	/**
	 * The current request opted out of the profiler stream
	 */
	protected bool $skipped = false;
	
	/**
	 * Correlates this CLI run's start/message/finish entries
	 */
	protected string $runId = '';
	
	protected float $startedAt = 0.0;
	
	/**
	 * The CLI stream write path failed — stop retrying for this run
	 */
	protected bool $cliBroken = false;
	
	public function __construct()
	{
		if($this->isStreamEnabled() === false)
		{
			$this->enabled = false;
			
			return;
		}
		
		if($this->request->isCli())
		{
			$this->startCliRun();
		}
		
		// flush after the response has been sent to the client (post
		// fastcgi_finish_request), so the redis write never adds latency
		$this->app->afterResponse(function(): void
		{
			$this->flush();
		});
	}
	
	/**
	 * Excludes the CURRENT request from the profiler stream - for
	 * long-lived SSE endpoints, whose lifetime-aggregated profile is
	 * noise (a 55s worker stream ticking 5 redis commands per second
	 * lands as one 275-query entry); the profiler's own stream endpoint
	 * is excluded the same way
	 */
	public function skip(): static
	{
		$this->skipped = true;
		
		return $this;
	}
	
	protected function isStreamEnabled(): bool
	{
		return $this->profilers?->enabled === true
			&& $this->profilers->stream?->enabled === true;
	}
	
	/**
	 * Builds the profile and appends it to the session's redis stream
	 */
	protected function flush(): void
	{
		try
		{
			// CLI runs close on the shared CLI stream instead; skip()
			// slims their finish entry rather than dropping the run
			if($this->request->isCli())
			{
				$this->finishCliRun();
				
				return;
			}
			
			// never stream the profiler's own SSE endpoint or a request
			// that opted out (long-lived SSE loops)
			if($this->skipped === true
				|| $this->request->getControllerClass() === 'Profiler')
			{
				return;
			}
			
			// handler-agnostic, and WITHOUT requiring a started session:
			// the profiler pairs writer and reader by the browser session,
			// so the id the request presented in its cookie is the key -
			// many requests never touch the session at all (the reader's
			// SSE endpoint minted the cookie in the first place)
			$session = $this->app->getServices()->session;
			$sessionId = $session instanceof Session
				? (string)($session->getId() ?? $session->getCookieId())
				: '';
			if($sessionId === '')
			{
				return;
			}
			
			$client = $this->getClient();
			if($client === null)
			{
				return;
			}
			
			$stream = $this->profilers->stream;
			$key = (string)$stream->key_prefix . $sessionId;
			$payload = $this->buildPayload();
			
			$client->xAdd(
				$key,
				'*',
				[
					// substitute/skip invalid bytes: captured queries and
					// redis args are arbitrary — one bad byte must not turn
					// the whole entry into an empty body (bare json_encode
					// returns false)
					'body' => (string)json_encode($payload,
						JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR),
					'method' => (string)$payload['method'],
					'uri' => (string)$payload['uri'],
					'req_id' => (string)$payload['req_id'],
					'ts' => (string)$payload['ts'],
				],
				(int)$stream->maxlen,
				true, // approximate trim (~)
			);
			
			// missing ttl config must not become EXPIRE key 0 — that would
			// delete the stream the moment it is written
			$ttl = (int)($stream->ttl ?? self::TTL_DEFAULT);
			$client->expire($key, $ttl > 0 ? $ttl : self::TTL_DEFAULT);
		}
		catch(Throwable $throwable)
		{
			// a dev profiler must never break the request lifecycle
		}
	}
	
	/**
	 * @return array<string, mixed>
	 */
	protected function buildPayload(): array
	{
		// benchmark is optional like streams — a throwing get() here would
		// make flush()'s catch silently drop the whole profile
		$measurements = [];
		if($this->container->isRegistered(Benchmark::SYMBOL))
		{
			$benchmark = $this->container->get(Benchmark::SYMBOL);
			$benchmark->stop(); // finalize the total (xhr never renders the helper)
			
			foreach($benchmark->getMeasurements() as $name => $measurement)
			{
				$measurements[$name] = [
					'time' => $measurement->getTotalTime(),
					'memory' => $measurement->getTotalMemory(),
				];
			}
		}
		
		return [
			'req_id' => uniqid('', true),
			'ts' => (int)round(microtime(true) * 1000),
			'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
			'uri' => $_SERVER['REQUEST_URI'] ?? '',
			'ajax' => $this->request->isXmlHttpRequest(),
			'memory' => memory_get_peak_usage(true),
			'queries' => $this->highlightQueries((new QueriesReporter)->getReport() ?? []),
			'redis' => $this->highlightRedis((new RedisReporter)->getReport() ?? []),
			'streams' => $this->collectStreams(),
			'console' => $this->container->getClass(Console::class)->getReport(),
			'benchmark' => $measurements,
			'errors' => $this->collectErrors(),
		];
	}
	
	/**
	 * The payload's query rows carry <color> markup, which the profiler page
	 * renders through the same appendColorized() as the CLI pane. The stream is
	 * that page's private artifact — TTL'd, capped, no other consumer — so
	 * highlighting here keeps one set of rules for terminal and browser instead
	 * of a second copy of the keyword list and the thresholds in JS.
	 *
	 * @param ArrayObject[] $report
	 * @return array<int, array<string, mixed>>
	 */
	protected function highlightQueries(
		array $report,
	): array
	{
		$rows = [];
		foreach($report as $query)
		{
			$rows[] = [
				'sql' => Highlighter::sql((string)$query->sql),
				'parameters' => $query->parameters,
				'time' => Highlighter::time((string)$query->time),
				'memory' => Highlighter::memory((string)$query->memory),
			];
		}
		
		return $rows;
	}
	
	/**
	 * @param ArrayObject[] $report
	 * @return array<int, array<string, mixed>>
	 */
	protected function highlightRedis(
		array $report,
	): array
	{
		$rows = [];
		foreach($report as $command)
		{
			$rows[] = [
				'call' => Highlighter::redis((string)$command->call),
				'time' => Highlighter::time((string)$command->time),
				'memory' => Highlighter::memory((string)$command->memory),
			];
		}
		
		return $rows;
	}
	
	/**
	 * @return array<int, array<string, mixed>>
	 */
	protected function collectErrors(): array
	{
		$errors = [];
		
		foreach($this->app->getServices()->events as $event)
		{
			if($event instanceof Throwable === false)
			{
				continue;
			}
			
			$errors[] = [
				'type' => get_class($event),
				'message' => $event->getMessage(),
				'file' => $event->getFile(),
				'line' => $event->getLine(),
				'trace' => $this->buildTrace($event),
			];
		}
		
		return $errors;
	}
	
	/**
	 * The exception's trace as rendered by PHP (argument values are already
	 * elided there — no request payloads or credentials leak into the stream),
	 * capped at TRACE_MAX_LENGTH
	 */
	protected function buildTrace(
		Throwable $event,
	): string
	{
		$trace = $event->getTraceAsString();
		
		if(strlen($trace) > self::TRACE_MAX_LENGTH)
		{
			$trace = substr($trace, 0, self::TRACE_MAX_LENGTH)
				. PHP_EOL . '… [trace truncated]';
		}
		
		return $trace;
	}
	
	/**
	 * ovos/streams HTTP requests made during this request (empty when the
	 * streams service is not registered)
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function collectStreams(): array
	{
		// ovos/streams is optional — only touch it when the project registered it
		// (constructing it unregistered would fatal on the missing streams config)
		if($this->container->isRegistered(Streams::SYMBOL) === false)
		{
			return [];
		}
		
		$streams = [];
		
		foreach($this->container->get(Streams::SYMBOL)->getRequests() as $request)
		{
			$measurement = $request->getMeasurement();
			
			$streams[] = [
				'method' => $request->getMethod(),
				'url' => $request->getUrl(),
				'time' => $measurement?->getTotalTime(),
				'memory' => $measurement?->getTotalMemory(),
			];
		}
		
		return $streams;
	}
	
	/**
	 * Opens the run on the shared CLI stream: a start entry now, one live
	 * entry per Terminal::output() message, the finish entry from flush()
	 */
	protected function startCliRun(): void
	{
		$this->runId = uniqid('', true);
		$this->startedAt = microtime(true);
		
		$this->writeCli([
			'kind' => 'start',
			'run_id' => $this->runId,
			'ts' => (int)round(microtime(true) * 1000),
			'command' => implode(' ', (array)($_SERVER['argv'] ?? [])),
			'pid' => (int)getmypid(),
			'source' => $this->getCliSource(),
		]);
		
		Terminal::listen(function(
			string $message,
			bool $markup,
		): void
		{
			$this->writeCli([
				'kind' => 'message',
				'run_id' => $this->runId,
				'ts' => (int)round(microtime(true) * 1000),
				'message' => $message,
				'markup' => $markup,
			]);
		});
	}
	
	/**
	 * Origin badge for the run — crontab exports PROFILER_SOURCE=cron (one
	 * line at the top covers every job); absent or odd-looking values read
	 * as a manual run
	 */
	protected function getCliSource(): string
	{
		$source = (string)($_SERVER['PROFILER_SOURCE'] ?? getenv('PROFILER_SOURCE'));
		
		if(preg_match('/^[a-z0-9_-]{1,16}$/', $source) !== 1)
		{
			return 'manual';
		}
		
		return $source;
	}
	
	/**
	 * Closes the run: duration, peak memory and collected errors always;
	 * the query/redis/console reports only when the run did not opt out
	 * via skip() (a long-lived worker's lifetime aggregate is noise)
	 */
	protected function finishCliRun(): void
	{
		if($this->runId === '')
		{
			return;
		}
		
		$payload = [
			'kind' => 'finish',
			'run_id' => $this->runId,
			'ts' => (int)round(microtime(true) * 1000),
			'duration' => round(microtime(true) - $this->startedAt, 3),
			'memory' => memory_get_peak_usage(true),
			'errors' => $this->collectErrors(),
		];
		
		if($this->skipped === false)
		{
			$payload['queries'] = $this->highlightQueries((new QueriesReporter)->getReport() ?? []);
			$payload['redis'] = $this->highlightRedis((new RedisReporter)->getReport() ?? []);
			$payload['console'] = $this->container->getClass(Console::class)->getReport();
		}
		
		$this->writeCli($payload);
	}
	
	/**
	 * Appends one entry to the shared CLI stream. Self-disabling on the
	 * first failure — a dev profiler must never slow or break the run by
	 * retrying a dead connection on every message.
	 *
	 * @param array<string, mixed> $payload
	 */
	protected function writeCli(
		array $payload,
	): void
	{
		if($this->cliBroken === true)
		{
			return;
		}
		
		try
		{
			$client = $this->getClient();
			if($client === null)
			{
				$this->cliBroken = true;
				
				return;
			}
			
			$stream = $this->profilers->stream;
			$key = (string)$stream->key_prefix . self::CLI_KEY;
			
			// our own XADD/EXPIRE must not land in the redis report the
			// finish entry carries
			RedisCollector::$paused = true;
			
			$client->xAdd(
				$key,
				'*',
				[
					'body' => (string)json_encode($payload,
						JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR),
					'kind' => (string)$payload['kind'],
					'run_id' => (string)$payload['run_id'],
					'ts' => (string)$payload['ts'],
				],
				(int)($stream->cli_maxlen ?? self::CLI_MAXLEN_DEFAULT),
				true, // approximate trim (~)
			);
			
			$ttl = (int)($stream->ttl ?? self::TTL_DEFAULT);
			$client->expire($key, $ttl > 0 ? $ttl : self::TTL_DEFAULT);
		}
		catch(Throwable $throwable)
		{
			// a dev profiler must never break the run
			$this->cliBroken = true;
		}
		finally
		{
			RedisCollector::$paused = false;
		}
	}
	
	protected function getClient(): ?RedisClient
	{
		$client = $this->container
			->getClass(Connections::class)
			->get((string)$this->profilers->stream->connection)
			->getClient();
		
		return $client instanceof RedisClient ? $client : null;
	}
}
