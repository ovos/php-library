<?php
declare(strict_types=1);

namespace Ovos;

use ResourceBundle;
use Collator;

use function array_reverse;
use function array_key_exists;
use function is_numeric;
use function str_starts_with;

/**
 * Locale
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Locale
{
	protected ?ArrayObject $config = null;
	
	// Default values of the default system locale
	public const string DEFAULT_URL_NAME = 'en';
	public const string DEFAULT_SYMBOL = 'en_US';
	
	public string $urlName;
	
	public string $symbol;
	
	public string $language;
	
	public string $country;
	
	public string $name;
	
	public bool $default = false;
	
	public bool $locked = false;
	
	protected ?Translator $translator = null;
	
	public function __construct(
		string $urlName,
		?ArrayObject $config = null,
	)
	{
		$this->setUrlName($urlName);
		
		if($config !== null)
		{
			$this->fromConfig($config);
		}
	}
	
	public function fromConfig(
		ArrayObject $config,
	): static
	{
		$this->setConfig($config);
		
		if($config->offsetExists('symbol') === false)
		{
			throw new Exception('"symbol" property is mandatory for locale.');
		}
		
		$this->setSymbol($config->symbol);
		
		if($config->language)
		{
			$this->setLanguage($config->language);
		}
		if($config->country)
		{
			$this->setCountry($config->country);
		}
		if($config->name)
		{
			$this->setName($config->name);
		}
		if($config->default)
		{
			$this->setDefault($config->default);
		}
		
		return $this;
	}
	
	public function getConfig(): ArrayObject
	{
		return $this->config;
	}
	
	public function setConfig(
		ArrayObject $config,
	): static
	{
		$this->config = $config;
		
		return $this;
	}
	
	public function getUrlName(): string
	{
		return $this->urlName;
	}
	
	public function setUrlName(
		string $urlName,
	): static
	{
		$this->urlName = $urlName;
		
		return $this;
	}
	
	public function getSymbol(): string
	{
		return $this->symbol;
	}
	
	public function setSymbol(
		string $symbol,
	): static
	{
		$this->symbol = $symbol;
		
		return $this;
	}
	
	public function getLanguage(): string
	{
		return $this->language;
	}
	
	public function setLanguage(
		string $language,
	): static
	{
		$this->language = $language;
		
		return $this;
	}
	
	public function getCountry(): string
	{
		return $this->country;
	}
	
	public function setCountry(
		string $country,
	): static
	{
		$this->country = $country;
		
		return $this;
	}
	
	public function getName(): string
	{
		return $this->name;
	}
	
	public function setName(
		string $name,
	): static
	{
		$this->name = $name;
		
		return $this;
	}
	
	public function setDefault(
		bool $default,
	): static
	{
		$this->default = $default;
		
		return $this;
	}
	
	public function isDefault(): bool
	{
		return $this->default;
	}
	
	public function setLocked(
		bool $locked,
	): static
	{
		$this->locked = $locked;
		
		return $this;
	}
	
	public function isLocked(): bool
	{
		return $this->locked;
	}
	
	/**
	 * Support for selective translations
	 * (only for some controllers - configured in yml)
	 */
	public function isLockedForRequest(
		Request $request,
	): bool
	{
		if($this->config === null)
		{
			return $this->locked;
		}
		
		$controllers = $this->config->offsetGet('controllers');
		if($controllers === null)
		{
			return $this->locked;
		}
		
		// lock list of controllers exists, check if this controller is within this list
		$currentController = $request->getControllerClass();
		
		$locked = true;
		foreach($controllers as $controller)
		{
			// if controller matches (begins with the same name)
			if(str_starts_with($currentController, $controller))
			{
				$locked = false;
				break;
			}
		}
		
		return $locked;
	}
	
	public function getTranslator(): Translator
	{
		if($this->translator === null)
		{
			$this->translator = new Translator($this);
		}
		
		return $this->translator;
	}
	
	public function getCountries(
		array $top = [],
	): array
	{
		$countries = [];
		
		// fetch all world regions
		$regions = new ResourceBundle($this->getLanguage(), 'ICUDATA-region');
		foreach($regions->get('Countries') as $symbol => $region)
		{
			if(is_numeric($symbol)) // continents
			{
				continue;
			}
			
			if($symbol === 'ZZ') // ZZ = whole world
			{
				continue;
			}
			
			$countries[$symbol] = $region;
		}
		
		$collator = new Collator($this->getSymbol());
		$collator->asort($countries);
		
		// move selected keys to the top
		foreach(array_reverse($top) as $key)
		{
			if(array_key_exists($key, $countries) === false)
			{
				continue;
			}
			
			$countries = [$key => $countries[$key]] + $countries;
		}
		
		return $countries;
	}
	
	public function __debugInfo(): array
	{
		return [
			'url_name' => $this->urlName,
			'symbol' => $this->symbol,
			'language' => $this->language,
			'country' => $this->country,
			'name' => $this->name,
			'default' => $this->default,
			'locked' => $this->locked,
		];
	}
}
