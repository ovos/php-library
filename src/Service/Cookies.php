<?php
declare(strict_types=1);

namespace Ovos\Service;

use Ovos\ArrayObject;
use Ovos\Container\Inject;
use Ovos\Exception;
use Ovos\Service;

use function strlen;

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
	public const string SYMBOL = 'cookies';
	
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
			if(str_starts_with($name, $this->_prefix))
			{
				unset($_COOKIE[$name]);
				$name = substr($name, strlen($this->_prefix));
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
		$options['domain'] = $this->_app->getDomain(); // if we pass null here, then the domain will be set to the current domain
		$options['samesite'] = $this->_cookiesConfig->samesite;
		$options['secure'] = $this->_request->isSecure();
		// https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Set-Cookie/SameSite
		// SameSite=None works only with Secure
		if($options['secure'] === false
			&& ($options['samesite'] === 'None' || $options['samesite'] === null))
		{
			$options['samesite'] = 'Lax';
		}
		
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
	public function get(string $name): ?string
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
	public function unset(string $name): bool
	{
		if(!isset($_COOKIE[$name]))
		{
			return false;
		}
		
		setcookie($this->getName($name), '', [
			'expires' => -1,
			'path' => SYSTEM_PATH,
			'domain' => $this->_app->getDomain(),
		]);
		unset($_COOKIE[$name]);
		
		return true;
	}
}
