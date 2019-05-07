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
	 * Default system locale
	 */
	public const DEFAULT = 'en';

	/**
	 * @var string
	 */
	protected $_symbol;

	/**
	 * @var bool
	 */
	protected $_default = false;

	/**
	 * @var string
	 */
	protected $_name;

	/**
	 * @var Translator
	 */
	protected $_translator;

	/**
	 * @param string $symbol
	 * @param bool $default
	 * @param string $name
	 */
	public function __construct(string $symbol = null, bool $default = false, string $name = null)
	{
		$this->setSymbol($symbol);
		$this->setDefault($default);
		$this->setName($name);
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
			'symbol' => $this->_symbol,
			'name' => $this->_name,
			'default' => $this->_default,
		];
	}
}
