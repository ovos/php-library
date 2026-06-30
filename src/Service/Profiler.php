<?php
declare(strict_types=1);

namespace Ovos\Service;

use Ovos\ArrayObject;
use Ovos\Connections;
use Ovos\Console;
use Ovos\Container\ArrayObject as InjectArrayObject;
use Ovos\Container\Inject;
use Ovos\Pdo\Profiler\Reporter as QueriesReporter;
use Ovos\Redis\Profiler\Reporter as RedisReporter;
use Ovos\Service;
use Redis as RedisClient;
use Throwable;

use function get_class;
use function json_encode;
use function memory_get_peak_usage;
use function microtime;
use function round;
use function session_id;
use function uniqid;

/**
 * Profiler
 *
 * Streams the per-request profile (queries, redis commands, console messages,
 * benchmark and errors) onto a redis stream keyed by session, so the profiler
 * panel and the /profiler/ surface can tail it live — including on XHR/JSON
 * requests, where the panel itself never renders.
 *
 * Dev-only and self-disabling: registers nothing unless both
 * profilers.enabled and profilers.stream.enabled are set.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Profiler extends Service
{
	public const string SYMBOL = 'profiler';
	
	#[Inject('config')]
	#[InjectArrayObject('system', 'profilers')]
	protected ?ArrayObject $profilers = null;
	
	public function __construct()
	{
		if($this->isStreamEnabled() === false)
		{
			$this->enabled = false;
			
			return;
		}
		
		// flush after the response has been sent to the client (post
		// fastcgi_finish_request), so the redis write never adds latency
		$this->app->afterResponse(function(): void
		{
			$this->flush();
		});
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
			// http only; never stream the profiler's own SSE endpoint
			if($this->request->isCli()
				|| $this->request->getControllerClass() === 'Profiler')
			{
				return;
			}
			
			$sessionId = session_id();
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
					'body' => (string)json_encode($payload),
					'method' => (string)$payload['method'],
					'uri' => (string)$payload['uri'],
					'req_id' => (string)$payload['req_id'],
					'ts' => (string)$payload['ts'],
				],
				(int)$stream->maxlen,
				true, // approximate trim (~)
			);
			$client->expire($key, (int)$stream->ttl);
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
		$benchmark = $this->container->get(Benchmark::SYMBOL);
		$benchmark->stop(); // finalize the total (xhr never renders the helper)
		
		$measurements = [];
		foreach($benchmark->getMeasurements() as $name => $measurement)
		{
			$measurements[$name] = [
				'time' => $measurement->getTotalTime(),
				'memory' => $measurement->getTotalMemory(),
			];
		}
		
		return [
			'req_id' => uniqid('', true),
			'ts' => (int)round(microtime(true) * 1000),
			'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
			'uri' => $_SERVER['REQUEST_URI'] ?? '',
			'ajax' => $this->request->isXmlHttpRequest(),
			'memory' => memory_get_peak_usage(true),
			'queries' => (new QueriesReporter)->getReport() ?? [],
			'redis' => (new RedisReporter)->getReport() ?? [],
			'console' => $this->container->getClass(Console::class)->getReport(),
			'benchmark' => $measurements,
			'errors' => $this->collectErrors(),
		];
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
			];
		}
		
		return $errors;
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
