<?php
declare(strict_types=1);

namespace Ovos\Service;

use Ovos\ArrayObject;
use Ovos\Container\Inject;
use Ovos\Exception;
use Ovos\Redis\Connection;
use Ovos\Service;

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
	/**
	 * @var string
	 */
	public const string SYMBOL = 'session';
	
	/**
	 * @var ArrayObject
	 */
	protected ArrayObject $_config;
	
	/**
	 * @var ArrayObject
	 */
	protected ArrayObject $_cookiesConfig;
	
	/**
	 * @var ArrayObject
	 */
	protected ArrayObject $_sessionConfig;
	
	/**
	 * @var bool
	 */
	protected bool $_initialized = false;
	
	/**
	 * @var array
	 */
	protected array $_session = [];
	
	/**
	 * @param ArrayObject $config
	 *
	 * @throws Exception
	 */
	public function __construct(
		#[Inject('config')] ArrayObject $config,
	)
	{
		$this->_config = $config;
		if($this->_config->cookies === null)
		{
			throw new Exception('"cookies" config section is missing.');
		}
		$this->_cookiesConfig = $this->_config->cookies;
		
		if($this->_config->session === null)
		{
			throw new Exception('"session" config section is missing.');
		}
		$this->_sessionConfig = $this->_config->session;
		
		if($this->_sessionConfig->ini)
		{
			foreach($this->_sessionConfig->ini as $ini => $value)
			{
				ini_set('session.' . $ini, (string)$value);
			}
		}
		
		if($this->_sessionConfig->autostart)
		{
			$this->start();
		}
	}
	
	/**
	 */
	protected function _initialize(): void
	{
		if($this->_initialized === false)
		{
			session_cache_limiter($this->_sessionConfig->cache_limiter);
			
			$cookie = session_get_cookie_params();
			$options = [
				'lifetime' => $cookie['lifetime'],
				'path' => SYSTEM_PATH,
				'domain' => $this->_app->getDomain(), // if we pass null here, then the domain will be set to the current domain
				'secure' => $this->_request->isSecure(),
				'httponly' => true,
				'samesite' => $this->_cookiesConfig->samesite,
			];
			// https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Set-Cookie/SameSite
			// SameSite=None works only with Secure
			if($options['secure'] === false
				&& ($options['samesite'] === 'None' || $options['samesite'] === null))
			{
				$options['samesite'] = 'Lax';
			}
			session_set_cookie_params($options);
			
			if($this->_sessionConfig->cookie_name)
			{
				session_name($this->_sessionConfig->cookie_name);
			}
			
			if($this->_cookiesConfig->prefix)
			{
				session_name($this->_cookiesConfig->prefix . session_name());
			}
			
			$this->_initialized = true;
		}
	}
	
	public function start(): void
	{
		if($this->_request->isCli())
		{
			return;
		}
		
		$this->_initialize();
		if(session_start() === false)
		{
			throw new Exception('Session could not start.');
		}
		$this->_session = &$_SESSION;
	}
	
	public function close(): void
	{
		if($this->_request->isCli())
		{
			return;
		}
		
		session_write_close();
	}
	
	/**
	 * @see https://www.php.net/session_regenerate_id
	 * 
	 * @param bool $deleteOldSession
	 * 
	 * @return bool
	 */
	public function regenerateId(bool $deleteOldSession = true): bool
	{
		return session_regenerate_id($deleteOldSession);
	}
	
	/**
	 * @return bool
	 */
	public function flush(): bool
	{
		$connection = new Connection($this->_config->session->connection);
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
	
	/**
	 * @param string $name
	 *
	 * @return mixed
	 */
	public function &__get(string $name): mixed
	{
		if($this->__isset($name) === false)
		{
			$this->_session[$name] = null;
		}
		
		return $this->_session[$name];
	}
	
	/**
	 * @param string $name
	 *
	 * @return bool
	 */
	public function __isset(string $name): bool
	{
		return array_key_exists($name, $this->_session);
	}
	
	/**
	 * @param string $name
	 * @param mixed $value
	 */
	public function __set(string $name, mixed $value): void
	{
		$this->_session[$name] = $value;
	}
	
	/**
	 * @param string $name
	 */
	public function __unset(string $name): void
	{
		unset($this->_session[$name]);
	}
}
