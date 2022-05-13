<?php
declare(strict_types=1);

namespace Ovos;
use function count;
use function end;
use function key;
use function reset;
use function strpos;
use function is_string;
use function array_unshift;
use function array_merge;
use function implode;
use function preg_split;
use function array_shift;
use function strlen;

/**
 * Url
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Url
{
	/**
	 * @var null|Locale
	 */
	protected null|Locale $_locale = null;

	/**
	 * @var array
	 */
	protected array $_components = [];

	/**
	 * @var bool
	 */
	protected bool $_relative = false;

	/**
	 * Construct
	 *
	 * @param string|int[] $components
	 */
	public function __construct(...$components)
	{
		$componentsCount = count($components);
		if($componentsCount)
		{
			$urlComponents = [];
			foreach($components as $component)
			{
				$urlComponents[] = self::getUrlComponents($component);
			}
			unset($component);
			// https://github.com/kalessil/phpinspectionsea/blob/master/docs/performance.md#slow-array-function-used-in-loop
			$urlComponents = array_merge(...$urlComponents);
			
			$this->setComponents($urlComponents);

			return;
		}

		$this->setComponents(self::getRequestComponents(), true);
	}

	/**
	 * @param bool|int|float|string $url
	 *
	 * @return array
	 */
	public static function getUrlComponents(bool|int|float|string $url): array
	{
		if(is_string($url) === false)
		{
			return [$url];
		}
	
		$position = strpos($url, '/');
		if($position === false)
		{
			return [$url];
		}
		if($position === 0)
		{
			return [];
		}

		return preg_split('~/+~', trim($url, '/'));
	}

	/**
	 * @param array $components
	 * @param bool $relative
	 *
	 * @return ?string
	 */
	public static function getUrlFromComponents(array $components, bool $relative = false): ?string
	{
		$url = $relative ? '' : ROUTE_PATH;

		if(count($components) === 0)
		{
			return $url;
		}
		
		foreach($components as &$component)
		{
			if(is_int($component) || is_float($component))
			{
				$component = (string)$component;
			}
			else if(is_bool($component))
			{
				$component = $component ? 'true' : 'false';
			}
		}		

		return $url . implode('/', $components) . '/';
	}

	/**
	 * Returns request components
	 *
	 * @return array
	 */
	public static function getRequestComponents(): array
	{
		static $components;

		if($components === null)
		{
			$components = [];

			if(app()->isInterfaceHttp())
			{
				$uri = $_SERVER['REQUEST_URI'];
				$source = parse_url($uri, PHP_URL_PATH);
				$source = substr($source, strlen(ROUTE_PATH));
				if($source !== '' && $source !== false) // empty or ROUTE_PATH longer than source
				{
					$components = self::getUrlComponents($source);
				}
			}
			else if(app()->isInterfaceCli())
			{
				$components = $_SERVER['argv'];
				array_shift($components); // remove filename
			}
		}

		return $components;
	}

	/**
	 * @param array $components
	 * @param bool $detectLocale
	 *
	 * @return self
	 */
	public function setComponents(array $components, bool $detectLocale = false): self
	{
		// if first component is a locale symbol, use it
		if($detectLocale
			&& count($components)
			&& Locales::exists($components[0]))
		{
			$localeUrlName = array_shift($components);
			$this->setLocale(Locales::get($localeUrlName));
		}

		$this->_components = $components;

		return $this;
	}

	/**
	 * @param array $components
	 * @param bool $detectLocale
	 *
	 * @return self
	 */
	public function set(array $components, bool $detectLocale = false): self
	{
		return $this->setComponents($components, $detectLocale);
	}	

	/**
	 * Add *new* component
	 * 
	 * @param int|string $component
	 *
	 * @return self
	 */
	public function addComponent(int|string $component): self
	{
		$lastComponentKey = $this->_getLastComponentKey();
		if($this->_components[$lastComponentKey] !== $component)
		{
			$this->_components[] = $component;	
		}

		return $this;
	}
	
	/**
	 * @param string[] $components
	 *
	 * @return self
	 */
	public function add(...$components): self
	{
		foreach($components as $component)
		{
			$this->addComponent($component);
		}
	
		return $this;
	}
		
	/**
	 * @param string[] $components
	 *
	 * @return self
	 */
	public function remove(...$components): self
	{
		foreach($components as $component)
		{
			$this->removeComponent($component);
		}
	
		return $this;
	}
	
	/**
	 * Removes a component
	 * 
	 * @param int|string $component
	 *
	 * @return self
	 */
	public function removeComponent(int|string $component): self
	{
		if(($key = array_search($component, $this->_components, true)) !== false)
		{
			unset($this->_components[$key]);
		}

		return $this;
	}
	
	/**
	 * @param int|string|null $component (null to remove it)
	 *
	 * @return self
	 */
	public function setLastComponent(int|string|null $component): self
	{
		if(count($this->_components) === 0)
		{
			return $this;
		}
		
		$lastComponentKey = $this->_getLastComponentKey();
		if($component === null)
		{
			unset($this->_components[$lastComponentKey]);
		}
		else
		{
			$this->_components[$lastComponentKey] = $component;
		}

		return $this;
	}

	/**
	 * @return null|int|string
	 */
	protected function _getLastComponentKey(): null|int|string
	{
		end($this->_components);
		$lastComponentKey = key($this->_components);
		reset($this->_components);
		
		return $lastComponentKey;
	}
	
	/**
	 * @see setLastComponent
	 * 
	 * @param null|int|string $component (null to remove it)
	 *
	 * @return self
	 */
	public function setLast(null|int|string $component): self
	{
		return $this->setLastComponent($component);
	}

	/**
	 * @return array
	 */
	public function getComponents(): array
	{
		return $this->_components;
	}

	/**
	 * @param null|Locale|string $locale
	 *
	 * @return self
	 */
	public function setLocale(null|Locale|string $locale): self
	{
		if(is_string($locale))
		{
			$locale = Locales::get($locale);
		}

		$this->_locale = $locale;

		return $this;
	}

	/**
	 * @return ?Locale
	 */
	public function getLocale(): ?Locale
	{
		if($this->_locale === null)
		{
			$this->_locale = Locales::getDefault();
		}

		return $this->_locale;
	}

	/**
	 * @param bool $relative
	 *
	 * @return self
	 */
	public function setRelative(bool $relative): self
	{
		$this->_relative = $relative;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function getRelative(): bool
	{
		return $this->_relative;
	}

	/**
	 * @param bool $relative
	 *
	 * @return string
	 */
	public function getUrl(bool $relative = false): string
	{
		$components = $this->getComponents();
		
		$locale = $this->_locale;
		if($locale === null)
		{
			$locale = app()->getRequest()->getLocale();
		}

		if($locale->isDefault() === false)
		{
			array_unshift($components, $locale->getUrlName());
		}

		return self::getUrlFromComponents($components,
			$relative ?? $this->_relative);
	}

	/**
	 * @return string
	 */
	public function getWithHost(): string
	{
		return SYSTEM_HOST . $this->getUrl(false);
	}
	
	/**
	 * @return string
	 */
	public function __toString(): string
	{
		return $this->getUrl();
	}

	/**
	 * @return self
	 */
	public function getClone(): self
	{
		return clone $this;
	}
}
