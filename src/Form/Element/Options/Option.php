<?php
declare(strict_types=1);

namespace Ovos\Form\Element\Options;

use Ovos\Form\Element\Options;

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
	protected $_options;

	/**
	 * @var null|mixed
	 */
	protected $_value;

	/**
	 * @var null|mixed
	 */
	protected $_label;
	
	/**
	 * @param mixed $value
	 * @param null|mixed $label
	 */
	public function __construct($value, $label = null)
	{
		$this->setValue($value);
		$this->setLabel($label);
	}

	/**
	 * @param Options $options
	 * 
	 * @return $this
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
	 * @return $this
	 */
	public function setValue($value): self
	{
		$this->_value = $value;
		
		return $this;
	}

	/**
	 * @return mixed
	 */
	public function getValue()
	{
		return $this->_value;
	}

	/**
	 * @param null|mixed $label
	 * 
	 * @return $this
	 */
	public function setLabel($label): self
	{
		$this->_label = $label;
		
		return $this;
	}

	/**
	 * @return null|mixed
	 */
	public function getLabel()
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
		if($this->getOptions()->getValue() === null)
		{
			return false;
		}
	
		return (string)$this->getOptions()->getValue() === (string)$this->getValue();
	}
}
