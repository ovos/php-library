<?php
declare(strict_types=1);

namespace Ovos\Form\Element;

use Ovos\Form\Element;
use Ovos\Form\Element\Options\Option;
use function array_column;
use function is_array;
use function array_intersect;

/**
 * Options
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Options extends Element
{
	/**
	 * @var Option[]
	 */
	protected array $_options = [];

	/**
	 * @param array $options
	 */
	public function __construct(array $options = [])
	{
		$this->setOptions($options);
	}

	/**
	 * @param array $options
	 * @param string $valueKey
	 * @param string $labelKey
	 * 
	 * @return self
	 */
	public function fromObjects(array $options, string $valueKey,
		string $labelKey = null): self
	{
		foreach($options as $object)
		{
			$option = new Option(
				$object->{$valueKey}, 
				$labelKey ? $object->{$labelKey} : null,
				$object,
			);
			$this->addOption($option);
		}
		
		return $this;
	}

	/**
	 * @param array $options
	 * 
	 * @return self
	 */
	public function setOptions(...$options): self
	{
		if(is_array($options[0]))
		{
			$options = $options[0];
		}
	
		foreach($options as $option)
		{
			$this->addOption($option);
		}
		
		return $this;
	}

	/**
	 * @param int|string|Option $option
	 * 
	 * @return self
	 */
	public function addOption(int|string|Option $option): self
	{
		$optionObj = $option instanceof Option ? 
			$option : new Option($option);
		$optionObj->setOptions($this);
			
		$this->_options[] = $optionObj;	
	
		return $this;
	}

	/**
	 * @param array $options
	 * @param bool $keyIsValue
	 * 
	 * @return self
	 */
	public function addOptions(array $options, bool $keyIsValue = false): self
	{
		foreach($options as $key => $option)
		{
			if(($option instanceof Option) === false)
			{
				$option = $keyIsValue ?
					new Option($key, $option)
					: new Option($option);
			}
			
			$this->addOption($option);
		}
		
		return $this;
	}

	/**
	 * @return Option[]
	 */
	public function getOptions(): array
	{
		return $this->_options;
	}

	/**
	 * @return string[]
	 */
	public function getOptionsValues(): array
	{
		$values = [];
	
		foreach($this->_options as $options)
		{
			$values[] = (string)$options->getValue();
		}
		
		return $values;
	}
	
	/**
	 * Validate value (or values) against options
	 * 
	 * @return bool
	 */
	public function isValid(): bool
	{
		$valuesSelected = $this->getValue();
		$values = $this->getOptionsValues();
	
		// multiple values (name[] of input)
		if(is_array($valuesSelected))
		{
			if(array_is_list($valuesSelected) === false) // associative array
			{
				$valuesSelected = array_keys($valuesSelected);
			}
			
			if(count(array_intersect($valuesSelected, $values)) === 0)
			{
				$this->setValue(null);
			}
		}
		// single value (name of input)
		else
		{
			$valueSelected = (string)$valuesSelected;
			if(in_array($valueSelected, $values, true) === false)
			{
				$this->setValue(null);
			}
		}
		
		return parent::isValid();
	}
}
