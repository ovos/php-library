<?php
declare(strict_types=1);

namespace Ovos\Service;

use Ovos\ArrayObject;
use Ovos\Service;

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
	protected $_config;

	/**
	 * @var string
	 */
	protected $_prefix;

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

		$this->_config = $this->_app->getConfig()->system->cookies;
		$this->_prefix = $this->_config->prefix;
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
	 * @param mixed ...$options
	 *
	 * @return bool
	 */
	public function set(...$options): bool
	{
		if(\count($options))
		{
			$options[0] = $this->getName($options[0]);
		}

		return setcookie(...$options);
	}

	/**
	 * @see http://php.net/setcookie
	 *
	 * @param array $options
	 *
	 * @return bool
	 */
	public function setIfMissing(...$options): bool
	{
		if(\count($options))
		{
			if($this->get($options[0]))
			{
				return true;
			}
		}

		return $this->set(...$options);
	}

	/**
	 * @param string $name
	 *
	 * @return string|null
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

		setcookie($this->getName($name), '', -1, SYSTEM_PATH);
		unset($_COOKIE[$name]);

		return true;
	}
}
