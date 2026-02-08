<?php
declare(strict_types=1);

namespace Ovos;

use function array_merge;
use function array_shift;
use function array_unshift;
use function count;
use function end;
use function implode;
use function is_string;
use function key;
use function parse_url;
use function preg_split;
use function reset;
use function strlen;
use function strpos;
use function substr;

/**
 * Url
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Url
{
	protected ?Locale $locale = null;
	
	protected array $components = [];
	
	protected bool $relative = false;
	
	/**
	 * @param string|int[] $components
	 */
	public function __construct(
		...$components,
	)
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
	
	public static function getUrlComponents(
		bool|int|float|string $url,
	): array
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
	
	public static function getUrlFromComponents(
		array $components,
		bool $relative = false,
	): ?string
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
				if($source === null // example: "//app.config.json"
					|| $source === false // malformed URL
				)
				{
					return $components;
				}
				
				$source = substr($source, strlen(ROUTE_PATH));
				if($source !== '') // empty or ROUTE_PATH longer than source
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
	
	public function setComponents(
		array $components,
		bool $detectLocale = false,
	): static
	{
		// if the first component is a locale symbol, use it
		if($detectLocale
			&& count($components)
			&& Locales::exists($components[0]))
		{
			$localeUrlName = array_shift($components);
			$this->setLocale(Locales::get($localeUrlName));
		}
		
		$this->components = $components;
		
		return $this;
	}
	
	public function set(
		array $components,
		bool $detectLocale = false,
	): static
	{
		return $this->setComponents($components, $detectLocale);
	}
	
	/**
	 * Add *new* component
	 */
	public function addComponent(
		int|string $component,
	): static
	{
		$lastComponentKey = $this->getLastComponentKey();
		if($this->components[$lastComponentKey] !== $component)
		{
			$this->components[] = $component;
		}
		
		return $this;
	}
	
	/**
	 * @param string[] $components
	 */
	public function add(
		...$components,
	): static
	{
		foreach($components as $component)
		{
			$this->addComponent($component);
		}
		
		return $this;
	}
		
	/**
	 * @param string[] $components
	 */
	public function remove(
		...$components,
	): static
	{
		foreach($components as $component)
		{
			$this->removeComponent($component);
		}
	
		return $this;
	}
	
	/**
	 * Removes a component
	 */
	public function removeComponent(
		int|string $component,
	): static
	{
		if(($key = array_search($component, $this->components, true)) !== false)
		{
			unset($this->components[$key]);
		}
		
		return $this;
	}
	
	/**
	 * @param int|string|null $component (null to remove it)
	 *
	 * @return static
	 */
	public function setLastComponent(
		int|string|null $component,
	): static
	{
		if(count($this->components) === 0)
		{
			return $this;
		}
		
		$lastComponentKey = $this->getLastComponentKey();
		if($component === null)
		{
			unset($this->components[$lastComponentKey]);
		}
		else
		{
			$this->components[$lastComponentKey] = $component;
		}
		
		return $this;
	}
	
	/**
	 * @return null|int|string
	 */
	protected function getLastComponentKey(
	): null|int|string
	{
		end($this->components);
		$lastComponentKey = key($this->components);
		reset($this->components);
		
		return $lastComponentKey;
	}
	
	/**
	 * @see setLastComponent
	 */
	public function setLast(
		null|int|string $component, // null to remove it
	): static
	{
		return $this->setLastComponent($component);
	}
	
	public function getComponents(): array
	{
		return $this->components;
	}
	
	public function setLocale(
		null|Locale|string $locale,
	): static
	{
		if(is_string($locale))
		{
			$locale = Locales::get($locale);
		}
		
		$this->locale = $locale;
		
		return $this;
	}
	
	public function getLocale(): ?Locale
	{
		if($this->locale === null)
		{
			$this->locale = Locales::getDefault();
		}
		
		return $this->locale;
	}
	
	public function setRelative(bool $relative): static
	{
		$this->relative = $relative;
		
		return $this;
	}
	
	public function getRelative(): bool
	{
		return $this->relative;
	}
	
	public function getUrl(
		bool $relative = false,
	): string
	{
		$components = $this->getComponents();
		
		$locale = $this->locale;
		if($locale === null)
		{
			$locale = app()->getRequest()->getLocale();
		}
		
		if($locale->isDefault() === false)
		{
			array_unshift($components, $locale->getUrlName());
		}
		
		return self::getUrlFromComponents($components,
			$relative || $this->relative);
	}
	
	public function getWithHost(): string
	{
		return SYSTEM_HOST . $this->getUrl(false);
	}
	
	public function __toString(): string
	{
		return $this->getUrl();
	}
	
	public function getClone(): static
	{
		return clone $this;
	}
}
