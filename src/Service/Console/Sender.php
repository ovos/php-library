<?php
declare(strict_types=1);

namespace Ovos\Service\Console;

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
use function function_exists;
use function json_encode;
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
	 * Queued payloads, keyed by spl_object_id for throwables so the
	 * Events service and the Logger hook never double-report one object
	 */
	protected array $queue = [];
	
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
		return $this->config !== null
			&& $this->config->enabled === true
			&& (string)$this->config->url !== ''
			&& (string)$this->config->key !== '';
	}
	
	public function getLogLevel(): int
	{
		return (int)($this->config?->log_level ?? 5);
	}
	
	public function captureException(
		Throwable $event,
		array $extra = [],
		?int $priority = null,
	): static
	{
		try
		{
			$this->queue[spl_object_id($event)] =
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
		if(self::$flushing || $this->isEnabled() === false)
		{
			return;
		}
		
		self::$flushing = true;
		
		try
		{
			// merge uncaught events collected by the Events service
			foreach($this->container->get(Events::SYMBOL) as $event)
			{
				if($event instanceof Throwable
					&& isset($this->queue[spl_object_id($event)]) === false)
				{
					$this->captureException($event);
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
		// the response is already sent — release the connection so the
		// HTTP call is invisible to the end user
		if(function_exists('fastcgi_finish_request')
			&& $this->app->isInterfaceHttp())
		{
			@fastcgi_finish_request();
		}
		
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
