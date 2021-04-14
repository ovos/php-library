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
	 * Default system locale (url name)
	 */
	public const DEFAULT = 'en';

	/**
	 * @var string
	 */
	protected string $_urlName;

	/**
	 * @var string
	 */
	protected string $_symbol;
	
	/**
	 * @var string
	 */
	protected string $_language;
	
	/**
	 * @var string
	 */
	protected string $_country;

	/**
	 * @var string
	 */
	protected string $_name;
	
	/**
	 * @var bool
	 */
	protected bool $_default = false;

	/**
	 * @var null|Translator
	 */
	protected null|Translator $_translator = null;

	/**
	 * @return string
	 */
	public function getUrlName(): string
	{
		return $this->_urlName;
	}

	/**
	 * @param string $urlName
	 *
	 * @return self
	 */
	public function setUrlName(string $urlName): self
	{
		$this->_urlName = $urlName;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getSymbol(): string
	{
		return $this->_symbol;
	}

	/**
	 * @param string $symbol
	 *
	 * @return self
	 */
	public function setSymbol(string $symbol): self
	{
		$this->_symbol = $symbol;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getLanguage(): string
	{
		return $this->_language;
	}

	/**
	 * @param string $language
	 * 
	 * @return self
	 */
	public function setLanguage(string $language): self
	{
		$this->_language = $language;
		
		return $this;
	}

	/**
	 * @return string
	 */
	public function getCountry(): string
	{
		return $this->_country;
	}

	/**
	 * @param string $country
	 *
	 * @return self
	 */
	public function setCountry(string $country): self
	{
		$this->_country = $country;
		
		return $this;
	}
	
	/**
	 * @return string
	 */
	public function getName(): string
	{
		return $this->_name;
	}

	/**
	 * @param string $name
	 *
	 * @return self
	 */
	public function setName(string $name): self
	{
		$this->_name = $name;

		return $this;
	}
	
	/**
	 * @param bool $default
	 *
	 * @return self
	 */
	public function setDefault(bool $default): self
	{
		$this->_default = $default;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isDefault(): bool
	{
		return $this->_default;
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
	public function getCountries($top = []): array
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
			'url_name' => $this->_urlName,
			'symbol' => $this->_symbol,
			'language' => $this->_language,
			'country' => $this->_country,
			'name' => $this->_name,
			'default' => $this->_default,
		];
	}
}
