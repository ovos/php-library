<?php
declare(strict_types=1);

namespace Ovos\Service\Console;

use Ovos\Application;
use Ovos\ArrayObject;
use Ovos\Client;
use Ovos\Container\ArrayObject as InjectArrayObject;
use Ovos\Container\Inject;
use Ovos\Service;
use Ovos\Service\Events;
use Ovos\Service\Logger;
use SplObjectStorage;
use Throwable;

use function array_values;
use function count;
use function curl_exec;
use function curl_init;
use function curl_setopt_array;
use function defined;
use function json_encode;
use function mb_substr;
use function rtrim;
use function session_id;
use function session_status;

use const CURLOPT_CONNECTTIMEOUT_MS;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_POST;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT_MS;
use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_PARTIAL_OUTPUT_ON_ERROR;
use const PHP_SESSION_ACTIVE;

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
	
	/**
	 * The Application instance flush() is registered on. The hook is
	 * re-armed when the instance changes so it always lands on the one
	 * whose handleShutdown() actually runs, not just the construction-
	 * time instance.
	 */
	protected ?Application $registeredWith = null;
	
	public function __construct(
		#[Inject('config')]
		#[InjectArrayObject('console')]
		?ArrayObject $config,
	)
	{
		$this->config = $config;
		
		// register the post-response flush eagerly (services boot once per
		// request): flush() must run even when nothing was captured
		// explicitly, to drain the Events service's uncaught errors
		$this->registerFlush();
	}
	
	public function isEnabled(): bool
	{
		return $this->config !== null
			&& $this->config->enabled === true
			&& (string)$this->config->url !== ''
			&& (string)$this->config->key !== '';
	}
	
	public function getLogLevel(): int
	{
		return (int)($this->config?->log_level ?? 5);
	}
	
	/**
	 * Registers the post-response flush on the *current* Application,
	 * re-registering when the instance changes. The construction-time
	 * instance is not guaranteed to be the one whose handleShutdown()
	 * fires for a later request, so the capture paths re-arm the hook.
	 */
	protected function registerFlush(): void
	{
		if($this->isEnabled() === false)
		{
			return;
		}
		
		$application = Application::$instance;
		if($application !== null
			&& $application !== $this->registeredWith)
		{
			$application->afterResponse([$this, 'flush']);
			$this->registeredWith = $application;
		}
	}
	
	public function captureException(
		Throwable $event,
		array $extra = [],
		?int $priority = null,
	): static
	{
		if($this->isEnabled() === false)
		{
			return $this;
		}
		
		$this->registerFlush();
		
		try
		{
			$this->enqueue($event, $priority, $extra, self::QUEUE_MAX);
		}
		catch(Throwable)
		{
			// never break the host application
		}
		
		return $this;
	}
	
	/**
	 * Queues one throwable payload, deduping by live object identity.
	 * offsetExists/offsetSet, not contains/attach — the aliases are
	 * deprecated in PHP 8.5 and the promoted deprecation would land in the
	 * capture catch, silently dropping the event. The storage HOLDS the
	 * reference, so a queued throwable cannot be freed and have its recycled
	 * object id collide with a later, different one.
	 */
	protected function enqueue(
		Throwable $event,
		?int $priority,
		array $extra,
		int $limit,
	): void
	{
		$this->seen ??= new SplObjectStorage;
		if($this->seen->offsetExists($event)
			|| count($this->queue) >= $limit)
		{
			return;
		}
		
		$this->seen->offsetSet($event);
		$this->queue[] = Payload::fromThrowable($event, $priority, $extra);
	}
	
	public function captureMessage(
		string $message,
		int $priority = 5,
		array $extra = [],
	): static
	{
		if($this->isEnabled() === false)
		{
			return $this;
		}
		
		$this->registerFlush();
		
		try
		{
			if(count($this->queue) >= self::QUEUE_MAX)
			{
				return $this;
			}
			
			$this->queue[] = Payload::fromMessage($message, $priority, $extra);
		}
		catch(Throwable)
		{
			// never break the host application
		}
		
		return $this;
	}
	
	/**
	 * Builds and posts the batch — called once from handleShutdown()
	 * after the response went out
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
			// merge uncaught errors collected by the Events service — read
			// only: other post-response consumers (the profiler stream) still
			// need the events; the Application drains them after the chain.
			// The merge gets headroom past QUEUE_MAX so uncaught errors are
			// not starved by a queue already filled with explicit captures,
			// while an error loop stays bounded; enqueue() dedupes via $seen.
			$events = $this->container->get(Events::SYMBOL);
			foreach($events as $event)
			{
				if($event instanceof Throwable)
				{
					$this->enqueue($event, null, [], self::QUEUE_MAX * 2);
				}
			}
			
			$logLevel = $this->getLogLevel();
			$context = null;
			
			$errors = [];
			foreach($this->queue as $payload)
			{
				if($payload['priority'] > $logLevel)
				{
					continue;
				}
				
				$context ??= $this->buildContext();
				
				$payload['type'] = $context['type'];
				$payload['context'] = $context['context']
					+ ['extra' => $payload['extra']];
				unset($payload['extra']);
				
				// optional deploy label (git sha, svn revision, any string)
				$release = (string)($this->config?->release ?? '');
				if($release !== '')
				{
					$payload['release'] = mb_substr($release, 0, 64);
				}
				
				$errors[] = $payload;
			}
			
			if($errors !== [])
			{
				$this->send((string)json_encode($errors,
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
	 * @return array{type: string, context: array}
	 */
	protected function buildContext(): array
	{
		$isCli = $this->app->isInterfaceCli();
		
		$context = [
			'dir' => defined('BASE_DIR') ? rtrim(BASE_DIR, '/\\') : '',
		];
		
		if($isCli)
		{
			$context['host'] = (string)($this->app->getConfig()->system->domain ?? '');
			$context['args'] = isset($_SERVER['argv'])
				? array_values((array)$_SERVER['argv'])
				: [];
		}
		else
		{
			$context['host'] = (string)($_SERVER['HTTP_HOST'] ?? '');
			$context['uri'] = (string)($_SERVER['REQUEST_URI'] ?? '');
			$context['method'] = (string)($_SERVER['REQUEST_METHOD'] ?? '');
			$context['referer'] = (string)($_SERVER['HTTP_REFERER'] ?? '');
			$context['ip'] = (string)Client::getIp();
			$context['ua'] = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
			
			if(session_status() === PHP_SESSION_ACTIVE)
			{
				$context['sessionId'] = (string)session_id();
			}
			
			$context['request'] = $this->buildRequest();
		}
		
		return [
			'type' => $isCli ? 'cli' : 'http',
			'context' => $context,
		];
	}
	
	/**
	 * Request variables, redacted with the Logger patterns
	 * (the console scrubs again server-side as a backstop)
	 */
	protected function buildRequest(): array
	{
		$request = [];
		
		try
		{
			$logger = $this->container->get(Logger::SYMBOL);
			
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
			// logger unavailable — send without request variables
		}
		
		return $request;
	}
	
	protected function send(
		string $json,
	): void
	{
		$handle = curl_init(
			rtrim((string)$this->config->url, '/') . '/api/v1/ingest');
		
		curl_setopt_array($handle, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $json,
			CURLOPT_HTTPHEADER => [
				'Content-Type: application/json',
				'X-Console-Key: ' . (string)$this->config->key,
			],
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT_MS => 300,
			CURLOPT_TIMEOUT_MS => (int)($this->config->timeout_ms ?? 1000),
		]);
		
		curl_exec($handle);
	}
}
