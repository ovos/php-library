<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\ArrayObject;
use Ovos\Locale;
use function Ovos\app;

/**
 * Url
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Url
{
	/**
	 * @var Locale
	 */
	protected $_locale;

	/**
	 * @var array
	 */
	protected $_components = [];

	/**
	 * @var bool
	 */
	protected $_relative = false;

	/**
	 * Construct
	 *
	 * @param string[] $components
	 */
	public function __construct(...$components)
	{
		$componentsCount = \count($components);
		if($componentsCount)
		{
			$urlComponents = [[]];
			foreach($components as &$component)
			{
				$component = (string)$component; // for ints
				$urlComponents[] = self::getUrlComponents($component);
			}
			// https://github.com/kalessil/phpinspectionsea/blob/master/docs/performance.md#slow-array-function-used-in-loop
			$urlComponents = array_merge(...$urlComponents);
			
			$this->setComponents($urlComponents);

			return;
		}

		$this->setComponents(self::getRequestComponents(), true);
	}

	/**
	 * @param string $url
	 *
	 * @return array
	 */
	public static function getUrlComponents(string $url): array
	{
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
	 * @return string
	 */
	public static function getUrlFromComponents(array $components, bool $relative = false): ?string
	{
		$url = $relative ? '' : ROUTE_PATH;

		if(\count($components) === 0)
		{
			return $url;
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
				$source = substr($source, \strlen(ROUTE_PATH));
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
	 * @return $this
	 */
	public function setComponents(array $components, bool $detectLocale = false): self
	{
		// if first component is a locale symbol, use it
		if($detectLocale
			&& \count($components)
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
	 * @return $this
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
	 * @return $this
	 */
	public function addComponent($component): self
	{
		end($this->_components);
		$lastComponentKey = key($this->_components);
		if($this->_components[$lastComponentKey] !== $component)
		{
			$this->_components[] = $component;	
		}

		return $this;
	}
	
	/**
	 * @param string[] $components
	 *
	 * @return $this
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
	 * @param int|string|null $component (null to remove it)
	 *
	 * @return $this
	 */
	public function setLastComponent($component): self
	{
		if(\count($this->_components) === 0)
		{
			return $this;
		}
	
		end($this->_components);
		$lastComponentKey = key($this->_components);
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
	 * @see setLastComponent
	 * 
	 * @param int|string|null $component (null to remove it)
	 *
	 * @return $this
	 */
	public function setLast(?string $component): self
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
	 * @param Locale|string $locale
	 *
	 * @return $this
	 */
	public function setLocale($locale): self
	{
		if(\is_string($locale))
		{
			$locale = Locales::get($locale);
		}

		$this->_locale = $locale;

		return $this;
	}

	/**
	 * @return null|Locale
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
	 * @return $this
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
	public function getUrl(bool $relative = null): string
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
	public function __toString()
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
