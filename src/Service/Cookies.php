<?php
declare(strict_types=1);

namespace Ovos\Service;

use Ovos\ArrayObject;
use Ovos\Exception;
use Ovos\Service;
use function count;

/**
 * Cookies
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Cookies extends Service
{
	/**
	 * @var string
	 */
	public const SYMBOL = 'cookies';

	/**
	 * @var ArrayObject
	 */
	protected ArrayObject $_config;

	/**
	 * @var ArrayObject
	 */
	protected ArrayObject $_cookiesConfig;

	/**
	 * @var string
	 */
	protected string $_prefix;

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

		$this->_config = $this->_app->getConfig();
		if($this->_config->cookies === null)
		{
			throw new Exception('Configuration missing for cookies.');
		}
		$this->_cookiesConfig = $this->_config->cookies;
		
		$this->_prefix = $this->_cookiesConfig->prefix;
		$this->stripPrefixes();
	}

	/**
	 * Strips cookie prefixes for easier usage of $_COOKIE
	 */
	public function stripPrefixes(): void
	{
		if($this->_prefix === null)
		{
			return;
		}

		foreach($_COOKIE as $name => $value)
		{
			if(0 === strpos($name, $this->_prefix))
			{
				unset($_COOKIE[$name]);
				$name = substr($name, \strlen($this->_prefix));
				$_COOKIE[$name] = $value;
			}
		}
	}

	/**
	 * @param string $name
	 *
	 * @return string
	 */
	public function getName(string $name): string
	{
		if($this->_prefix === null)
		{
			return $name;
		}

		return $this->_prefix . $name;
	}

	/**
	 * @see http://php.net/setcookie
	 *
	 * @param string $name
	 * @param string $value
	 * @param array $options
	 *
	 * @return bool
	 */
	public function set(string $name, string $value, array $options): bool
	{
		$name = $this->getName($name);
		$options['path'] = SYSTEM_PATH;
		$options['domain'] = $this->_config->domain;
		$options['samesite'] = $this->_cookiesConfig->samesite;
		
		return setcookie($name, $value, $options);
	}

	/**
	 * @see http://php.net/setcookie
	 *
	 * @param string $name
	 * @param string $value
	 * @param array $options
	 *
	 * @return bool
	 */
	public function setIfMissing(string $name, string $value, array $options): bool
	{
		if($this->get($name))
		{
			return true;
		}

		return $this->set($name, $value, $options);
	}

	/**
	 * @param string $name
	 *
	 * @return ?string
	 */
	public function get($name): ?string
	{
		if(!isset($_COOKIE[$name]))
		{
			return null;
		}

		return $_COOKIE[$name];
	}

	/**
	 * @param string $name
	 *
	 * @return bool
	 */
	public function unset($name): bool
	{
		if(!isset($_COOKIE[$name]))
		{
			return false;
		}
		
		setcookie($this->getName($name), '', [
			'expires' => -1,
			'path' => SYSTEM_PATH,	
			'domain' => $this->_config->domain,
		]);
		unset($_COOKIE[$name]);

		return true;
	}
}
