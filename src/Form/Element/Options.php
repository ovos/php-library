<?php
declare(strict_types=1);

namespace Ovos\Form\Element;

use Ovos\Form\Element;
use Ovos\Form\Element\Options\Option;
use Override;

use function array_intersect;
use function array_is_list;
use function array_keys;
use function count;
use function in_array;
use function is_array;

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
	protected array $options = [];
	
	public function __construct(
		array $options = [],
	)
	{
		$this->setOptions($options);
	}
	
	public function clearOptions(): static
	{
		$this->options = [];
		
		return $this;
	}
	
	public function setOptions(
		...$options,
	): static
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
	
	public function addOption(
		mixed $key, // mixed|Option
		mixed $value = null,
		?object $object = null
	): static
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
		$this->options[] = $option;
		
		return $this;
	}
	
	public function addOptions(
		array $options,
	): static
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
	
	public function fromObjects(
		array $options,
		string $valueKey,
		?string $labelKey = null,
	): static
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
		return $this->options;
	}
	
	/**
	 * @return string[]
	 */
	public function getOptionsValues(): array
	{
		$values = [];
		
		foreach($this->options as $options)
		{
			$values[] = (string)$options->getValue();
		}
		
		return $values;
	}
	
	/**
	 * Validate value (or values) against options
	 */
	#[Override]
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
