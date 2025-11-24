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
	/**
	 * @var Options
	 */
	protected Options $_options;
	
	/**
	 * @var null|mixed
	 */
	protected mixed $_value = null;
	
	/**
	 * @var null|mixed
	 */
	protected mixed $_label = null;
	
	/**
	 * Object associated with the option
	 * Can be used for further processing of the option
	 *
	 * @var ?object
	 */
	protected ?object $_object = null;
	
	/**
	 * @param mixed $value
	 * @param null|mixed $label
	 * @param ?object $object
	 */
	public function __construct(mixed $value,
		mixed $label = null,
		?object $object = null)
	{
		$this->setValue($value);
		$this->setLabel($label);
		$this->setObject($object);
	}
	
	/**
	 * @param Options $options
	 *
	 * @return self
	 */
	public function setOptions(Options $options): self
	{
		$this->_options = $options;
		
		return $this;
	}
	
	/**
	 * @return Options
	 */
	public function getOptions(): Options
	{
		return $this->_options;
	}
	
	/**
	 * @param mixed $value
	 *
	 * @return self
	 */
	public function setValue($value): self
	{
		$this->_value = $value;
		
		return $this;
	}
	
	/**
	 * @return mixed
	 */
	public function getValue(): mixed
	{
		return $this->_value;
	}
	
	/**
	 * @param null|mixed $label
	 *
	 * @return self
	 */
	public function setLabel($label): self
	{
		$this->_label = $label;
		
		return $this;
	}
	
	/**
	 * @return null|mixed
	 */
	public function getLabel(): mixed
	{
		if($this->_label !== null)
		{
			return $this->_label;
		}
		
		return $this->getValue();
	}
	
	/**
	 * @return bool
	 */
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
	
	/**
	 * @param ?object $object
	 *
	 * @return self
	 */
	public function setObject(?object $object): self
	{
		$this->_object = $object;
		
		return $this;
	}
	
	/**
	 * @return ?object
	 */
	public function getObject(): ?object
	{
		return $this->_object;
	}
}
