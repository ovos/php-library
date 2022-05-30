<?php
declare(strict_types=1);

namespace Ovos;

use ResourceBundle;
use Collator;

use function array_reverse;
use function array_key_exists;
use function is_numeric;

/**
 * Locale
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Locale
{
	/**
	 * @var ?ArrayObject
	 */
	protected ?ArrayObject $_config = null;
	
	/**
	 * Default system locale (url name)
	 */
	public const DEFAULT = 'en';

	/**
	 * @var string
	 */
	public string $urlName;

	/**
	 * @var string
	 */
	public string $symbol;
	
	/**
	 * @var string
	 */
	public string $language;
	
	/**
	 * @var string
	 */
	public string $country;

	/**
	 * @var string
	 */
	public string $name;
	
	/**
	 * @var bool
	 */
	public bool $default = false;
			
	/**
	 * @var bool
	 */
	public bool $locked = false;

	/**
	 * @var null|Translator
	 */
	protected null|Translator $_translator = null;
	
	/**
	 */
	public function __construct(string $urlName, ?ArrayObject $config = null)
	{
		$this->setUrlName($urlName);
		
		if($config !== null)
		{
			$this->fromConfig($config);
		}
	}
	
	/**
	 * @param ArrayObject $config
	 *
	 * @return self
	 * @throws Exception
	 */
	public function fromConfig(ArrayObject $config): self
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
	
	/**
	 * @return ArrayObject
	 */
	public function getConfig(): ArrayObject
	{
		return $this->_config;
	}
	
	/**
	 * @param ArrayObject $config
	 *
	 * @return self
	 */
	public function setConfig(ArrayObject $config): self
	{
		$this->_config = $config;

		return $this;
	}
	
	/**
	 * @return string
	 */
	public function getUrlName(): string
	{
		return $this->urlName;
	}

	/**
	 * @param string $urlName
	 *
	 * @return self
	 */
	public function setUrlName(string $urlName): self
	{
		$this->urlName = $urlName;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getSymbol(): string
	{
		return $this->symbol;
	}

	/**
	 * @param string $symbol
	 *
	 * @return self
	 */
	public function setSymbol(string $symbol): self
	{
		$this->symbol = $symbol;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getLanguage(): string
	{
		return $this->language;
	}

	/**
	 * @param string $language
	 * 
	 * @return self
	 */
	public function setLanguage(string $language): self
	{
		$this->language = $language;
		
		return $this;
	}

	/**
	 * @return string
	 */
	public function getCountry(): string
	{
		return $this->country;
	}

	/**
	 * @param string $country
	 *
	 * @return self
	 */
	public function setCountry(string $country): self
	{
		$this->country = $country;
		
		return $this;
	}
	
	/**
	 * @return string
	 */
	public function getName(): string
	{
		return $this->name;
	}

	/**
	 * @param string $name
	 *
	 * @return self
	 */
	public function setName(string $name): self
	{
		$this->name = $name;

		return $this;
	}
	
	/**
	 * @param bool $default
	 *
	 * @return self
	 */
	public function setDefault(bool $default): self
	{
		$this->default = $default;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isDefault(): bool
	{
		return $this->default;
	}
	
	/**
	 * @param bool $locked
	 *
	 * @return self
	 */
	public function setLocked(bool $locked): self
	{
		$this->locked = $locked;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isLocked(): bool
	{
		return $this->locked;
	}

	/**
	 * @param Request $request
	 * 
	 * @return bool
	 */
	public function isLockedForRequest(Request $request): bool
	{
		if($this->_config === null)
		{
			return $this->locked;
		}
			
		$controllers = $this->_config->offsetGet('controllers');
		if($controllers === null)
		{
			return $this->locked;
		}
		
		// lock list of controllers exist, check if this controller is within this list
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
	
	/**
	 * @return Translator
	 */
	public function getTranslator(): Translator
	{
		if($this->_translator === null)
		{
			$this->_translator = new Translator($this);
		}

		return $this->_translator;
	}

	/**
	 * @param array $top
	 * 
	 * @return array
	 */
	public function getCountries(array $top = []): array
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
		
		// move selected keys to top
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
	
	/**
	 * @return array
	 */
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
