<?php
declare(strict_types=1);

namespace Ovos\Service;

use Ovos\Exception\MissingException\MissingConfigException;
use Ovos\Service;
use Ovos\ArrayObject;
use Ovos\Container\Inject;
use Ovos\Exception;
use Ovos\Connection\Redis as Connection;

use function array_key_exists;
use function ini_set;
use function session_cache_limiter;
use function session_get_cookie_params;
use function session_name;
use function session_regenerate_id;
use function session_set_cookie_params;
use function session_start;
use function session_write_close;

/**
 * Session
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Session extends Service
{
	public const string SYMBOL = 'session';
	
	protected ArrayObject $config;
	
	protected ArrayObject $cookiesConfig;
	
	protected ArrayObject $sessionConfig;
	
	protected bool $initialized = false;
	
	protected array $session = [];
	
	public function __construct(
		#[Inject('config')] ArrayObject $config,
	)
	{
		$this->config = $config;
		if($this->config->cookies === null)
		{
			throw new Exception(
				'"cookies" config section is missing.');
		}
		$this->cookiesConfig = $this->config->cookies;
		
		if($this->config->session === null)
		{
			throw new Exception(
				'"session" config section is missing.');
		}
		$this->sessionConfig = $this->config->session;
		
		if($this->sessionConfig->ini)
		{
			foreach($this->sessionConfig->ini as $ini => $value)
			{
				ini_set('session.' . $ini, (string)$value);
			}
		}
		
		if($this->sessionConfig->autostart)
		{
			$this->start();
		}
	}
	
	protected function initialize(): void
	{
		if($this->initialized === false)
		{
			session_cache_limiter($this->sessionConfig->cache_limiter);
			
			$cookie = session_get_cookie_params();
			$options = [
				'lifetime' => $cookie['lifetime'],
				'path' => SYSTEM_PATH,
				'domain' => $this->app->getDomain(), // if we pass null here, then the domain will be set to the current domain
				'secure' => $this->request->isSecure(),
				'httponly' => true,
				'samesite' => $this->cookiesConfig->samesite,
			];
			// https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Set-Cookie/SameSite
			// SameSite=None works only with Secure
			if($options['secure'] === false
				&& ($options['samesite'] === 'None' || $options['samesite'] === null))
			{
				$options['samesite'] = 'Lax';
			}
			session_set_cookie_params($options);
			
			if($this->sessionConfig->cookie_name)
			{
				session_name($this->sessionConfig->cookie_name);
			}
			
			if($this->cookiesConfig->prefix)
			{
				session_name($this->cookiesConfig->prefix . session_name());
			}
			
			$this->initialized = true;
		}
	}
	
	public function start(): void
	{
		if($this->request->isCli())
		{
			return;
		}
		
		$this->initialize();
		if(session_start() === false)
		{
			throw new Exception('Session could not start.');
		}
		$this->session = &$_SESSION;
	}
	
	public function close(): void
	{
		if($this->request->isCli())
		{
			return;
		}
		
		session_write_close();
	}
	
	/**
	 * @see https://www.php.net/session_regenerate_id
	 */
	public function regenerateId(
		bool $deleteOldSession = true,
	): bool
	{
		return session_regenerate_id($deleteOldSession);
	}
	
	public function flush(): bool
	{
		if($this->config->session->connection === null)
		{
			throw new MissingConfigException(
				'"connection" config section is missing.');
		}
		
		$connection = new Connection($this->config->session->connection);
		$connectionStatus = $connection->connect();
		if($connectionStatus === false)
		{
			return false;
		}
		
		if($client = $connection->getClient())
		{
			return $client->flushDB();
		}
		
		return false;
	}
	
	public function &__get(
		string $name,
	): mixed
	{
		if($this->__isset($name) === false)
		{
			$this->session[$name] = null;
		}
		
		return $this->session[$name];
	}
	
	public function __isset(
		string $name,
	): bool
	{
		return array_key_exists($name, $this->session);
	}
	
	public function __set(
		string $name,
		mixed $value,
	): void
	{
		$this->session[$name] = $value;
	}
	
	public function __unset(
		string $name,
	): void
	{
		unset($this->session[$name]);
	}
}
