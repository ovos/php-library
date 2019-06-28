<?php
declare(strict_types=1);

namespace Ovos;

use ResourceBundle;
use Collator;

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
	protected $_urlName;

	/**
	 * @var string
	 */
	protected $_symbol;
	
	/**
	 * @var string
	 */
	protected $_language;
	
	/**
	 * @var string
	 */
	protected $_country;

	/**
	 * @var string
	 */
	protected $_name;
	
	/**
	 * @var bool
	 */
	protected $_default = false;

	/**
	 * @var Translator
	 */
	protected $_translator;

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
	 * @return $this
	 */
	public function setUrlName(?string $urlName): self
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
	 * @return $this
	 */
	public function setSymbol(?string $symbol): self
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
	 * @return $this
	 */
	public function setLanguage(?string $language): self
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
	 * @return $this
	 */
	public function setCountry(?string $country): self
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
	 * @return $this
	 */
	public function setName(?string $name): self
	{
		$this->_name = $name;

		return $this;
	}
	
	/**
	 * @param bool $default
	 *
	 * @return $this
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
		$regions = new ResourceBundle($this->getSymbol(), 'ICUDATA-region');
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
	public function __debugInfo()
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
