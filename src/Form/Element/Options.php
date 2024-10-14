<?php
declare(strict_types=1);

namespace Ovos\Form\Element;

use Ovos\Form\Element;
use Ovos\Form\Element\Options\Option;

use function array_column;
use function is_array;
use function array_intersect;
use function array_is_list;

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
	 * @return self
	 */
	public function clearOptions(): self
	{
		$this->_options = [];
		
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
	 * @param mixed|Option $key
	 * @param mixed $value
	 * @param ?object $object
	 * 
	 * @return self
	 */
	public function addOption(
		mixed $key,
		mixed $value = null,
		?object $object = null
	): self
	{
		if($key instanceof Option)
		{
			$option = $key;
		}
		else
		{
			$optionArgs = [$key, $value, $object];
			$option = new Option(...$optionArgs);
		}
		
		$option->setOptions($this);
		$this->_options[] = $option;	
	
		return $this;
	}
	
	/**
	 * @param array $options
	 * 
	 * @return self
	 */
	public function addOptions(array $options): self
	{
		$isList = array_is_list($options);
		
		foreach($options as $key => $option)
		{
			if(($option instanceof Option) === false)
			{
				$option = $isList ?
					new Option($option)
					: new Option($key, $option); // $key = value, $option = label
			}
			
			$this->addOption($option);
		}
		
		return $this;
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
