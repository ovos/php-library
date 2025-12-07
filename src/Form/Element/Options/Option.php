<?php
declare(strict_types=1);

namespace Ovos\Form\Element\Options;

use Ovos\Form\Element\Options;

use function array_map;
use function in_array;
use function is_array;

/**
 * Option
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Option
{
	protected Options $options;
	
	protected mixed $value = null;
	
	protected mixed $label = null;
	
	/**
	 * Object associated with the option
	 * Can be used for further processing of the option
	 */
	protected ?object $object = null;
	
	public function __construct(
		mixed $value,
		mixed $label = null,
		?object $object = null,
	)
	{
		$this->setValue($value);
		$this->setLabel($label);
		$this->setObject($object);
	}
	
	public function setOptions(
		Options $options,
	): static
	{
		$this->options = $options;
		
		return $this;
	}
	
	public function getOptions(): Options
	{
		return $this->options;
	}
	
	public function setValue(
		mixed $value,
	): static
	{
		$this->value = $value;
		
		return $this;
	}
	
	public function getValue(): mixed
	{
		return $this->value;
	}
	
	public function setLabel(
		mixed $label,
	): static
	{
		$this->label = $label;
		
		return $this;
	}
	
	public function getLabel(): mixed
	{
		if($this->label !== null)
		{
			return $this->label;
		}
		
		return $this->getValue();
	}
	
	public function isSelected(): bool
	{
		// avoid selecting '' options, where no option is selected
		if(($selectedValues = $this->getOptions()->getInputValue()) === null)
		{
			return false;
		}
		
		$thisValue = (string)$this->getValue();
		// multiple values selected
		if(is_array($selectedValues))
		{
			$selectedValues = array_map('strval', $selectedValues);
			return in_array($thisValue, $selectedValues, true);
		}
		// single value selected
		$selectedValue = (string)$selectedValues;
		return $selectedValue === $thisValue;
	}
	
	public function setObject(
		?object $object,
	): static
	{
		$this->object = $object;
		
		return $this;
	}
	
	public function getObject(): ?object
	{
		return $this->object;
	}
}
