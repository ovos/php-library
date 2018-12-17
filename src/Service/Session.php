<?php
declare(strict_types=1);

namespace Ovos\Service;

use Ovos\ArrayObject;
use Ovos\Exception;
use Ovos\Service;

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
	public const SYMBOL = 'session';

	/**
	 * @var ArrayObject
	 */
	protected $_config;

	/**
	 * @var bool
	 */
	protected $_initialized = false;

	/**
	 * @var array
	 */
	protected $_session = [];

	/**
	 * @return string
	 */
	public function getSymbol(): string
	{
		return self::SYMBOL;
	}

	/**
	 */
	public function __construct()
	{
		parent::__construct();

		$this->_config = $this->_app->getConfig()->system->session;
		
		if($this->_config->ini)
		{
			foreach($this->_config->ini as $ini => $value)
			{
				ini_set('session.' . $ini, (string)$value);
			}
		}
	
		if($this->_config->autostart)
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
			session_cache_limiter($this->_config->cache_limiter);

			$cookie = session_get_cookie_params();
			session_set_cookie_params
			(
				$cookie['lifetime'],
				SYSTEM_DIR,
				$cookie['domain'],
				$this->_request->isSecure(),
				true
			);

			if($this->_config->cookie_name)
			{
				session_name($this->_config->cookie_name);
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
	 * @param bool $deleteOldSession
	 */
	public function regenerateId(bool $deleteOldSession = true): void
	{
		session_regenerate_id($deleteOldSession);
	}

	/**
	 * @param string $name
	 *
	 * @return mixed
	 */
	public function &__get(string $name)
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
	 *
	 * @return $this
	 */
	public function __set(string $name, $value): self
	{
		$this->_session[$name] = $value;

		return $this;
	}

	/**
	 * @param string $name
	 *
	 * @return $this
	 */
	public function __unset(string $name): self
	{
		unset($this->_session[$name]);

		return $this;
	}
}
