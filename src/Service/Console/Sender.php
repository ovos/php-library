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
use Throwable;

use function array_values;
use function curl_exec;
use function curl_init;
use function curl_setopt_array;
use function defined;
use function json_encode;
use function mb_substr;
use function rtrim;
use function session_id;
use function session_status;
use function spl_object_id;

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
	
	protected ?ArrayObject $config;
	
	/**
	 * Queued payloads. Throwables are keyed "object:<spl_object_id>" so the
	 * Events service and the Logger hook never double-report one object, and
	 * the string key cannot collide with the integer keys that
	 * captureMessage() appends; messages are appended.
	 */
	protected array $queue = [];
	
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
			$this->queue['object:' . spl_object_id($event)] =
				Payload::fromThrowable($event, $priority, $extra);
		}
		catch(Throwable)
		{
			// never break the host application
		}
		
		return $this;
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
		// long-lived service or bleed into a later request — including the
		// uncaught errors the Events service holds, since flush() is its
		// terminal consumer and nothing else drains them post-response
		if($this->isEnabled() === false)
		{
			$this->queue = [];
			
			try
			{
				$this->container->get(Events::SYMBOL)->clear();
			}
			catch(Throwable)
			{
				// events service unavailable — nothing to drain
			}
			
			return;
		}
		
		self::$flushing = true;
		
		try
		{
			// merge uncaught errors collected by the Events service, then drain
			// it: flush() is the terminal post-response consumer, so leaving them
			// in place would re-report on the next request in a reused worker
			$events = $this->container->get(Events::SYMBOL);
			foreach($events as $event)
			{
				if($event instanceof Throwable
					&& isset($this->queue['object:' . spl_object_id($event)]) === false)
				{
					$this->captureException($event);
				}
			}
			$events->clear();
			
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
