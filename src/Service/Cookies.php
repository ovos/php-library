<?php
declare(strict_types=1);

namespace Ovos\Service;

use Ovos\ArrayObject;
use Ovos\Container\Inject;
use Ovos\Exception;
use Ovos\Service;

use function setcookie;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Cookies
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Cookies extends Service
{
	public const string SYMBOL = 'cookies';
	
	protected ArrayObject $config;
	
	protected ArrayObject $cookiesConfig;
	protected ArrayObject $sessionConfig;
	
	protected ?string $prefix = null;
	
	public function __construct(
		#[Inject('config')] ArrayObject $config,
	)
	{
		$this->config = $config;
		if($this->config->cookies === null)
		{
			throw new Exception('"cookies" config section is missing.');
		}
		$this->cookiesConfig = $this->config->cookies;
		if($this->config->session === null)
		{
			throw new Exception(
				'"session" config section is missing.');
		}
		$this->sessionConfig = $this->config->session;
		
		$this->prefix = $this->cookiesConfig->prefix;
		$this->stripPrefixes();
	}
	
	/**
	 * Strips cookie prefixes for easier usage of $_COOKIE
	 */
	public function stripPrefixes(): void
	{
		if($this->prefix === null)
		{
			return;
		}
		
		foreach($_COOKIE as $name => $value)
		{
			if(str_starts_with($name, $this->prefix) === false)
			{
				continue;
			}
			
			// do not touch the session cookie
			if($name === $this->prefix . $this->sessionConfig->cookie_name)
			{
				continue;
			}
			
			unset($_COOKIE[$name]);
			$name = substr($name, strlen($this->prefix));
			$_COOKIE[$name] = $value;
		}
	}
	
	public function getName(
		string $name,
	): string
	{
		if($this->prefix === null)
		{
			return $name;
		}
		
		return $this->prefix . $name;
	}
	
	/**
	 * The cookie attribute policy: path, domain, samesite and secure are
	 * decided HERE for every cookie the framework sends - callers only
	 * add what is theirs to decide (expires, httponly)
	 */
	public function options(
		array $options = [],
	): array
	{
		$options['path'] = SYSTEM_PATH;
		$options['domain'] = $this->app->getDomain(); // if we pass null here, then the domain will be set to the current domain
		$options['samesite'] = $this->cookiesConfig->samesite;
		$options['secure'] = $this->request->isSecure();
		// https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Set-Cookie/SameSite
		// SameSite=None works only with Secure
		if($options['secure'] === false
			&& ($options['samesite'] === 'None' || $options['samesite'] === null))
		{
			$options['samesite'] = 'Lax';
		}
		
		return $options;
	}
	
	/**
	 * @see http://php.net/setcookie
	 */
	public function set(
		string $name,
		string $value,
		array $options,
	): bool
	{
		return setcookie($this->getName($name), $value,
			$this->options($options));
	}
	
	/**
	 * @see http://php.net/setcookie
	 */
	public function setIfMissing(
		string $name,
		string $value,
		array $options,
	): bool
	{
		if($this->get($name))
		{
			return true;
		}
		
		return $this->set($name, $value, $options);
	}
	
	public function get(
		string $name,
	): ?string
	{
		if(isset($_COOKIE[$name]) === false)
		{
			return null;
		}
		
		return $_COOKIE[$name];
	}
	
	public function unset(
		string $name,
	): bool
	{
		if(isset($_COOKIE[$name]) === false)
		{
			return false;
		}
		
		setcookie($this->getName($name), '', [
			'expires' => -1,
			'path' => SYSTEM_PATH,
			'domain' => $this->app->getDomain(),
		]);
		unset($_COOKIE[$name]);
		
		return true;
	}
}
