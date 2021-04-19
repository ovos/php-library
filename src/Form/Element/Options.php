<?php
declare(strict_types=1);

namespace Ovos\Form\Element;

use Ovos\Form\Element;
use Ovos\Form\Element\Options\Option;
use function array_column;
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
	 * @param string $columnKey
	 * @param string $indexKey
	 * 
	 * @return self
	 */
	public function fromObjects(array $options, string $columnKey,
		string $indexKey = null): self
	{
		$options = array_column($options, $columnKey, $indexKey);
		foreach($options as $key => $option)
		{
			$option = new Option($key, $option);
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
	 * Validate value against options
	 * 
	 * @return bool
	 */
	public function isValid(): bool
	{
		$value = (string)$this->getValue();
		$values = $this->getOptionsValues();
		if(in_array($value, $values, true) === false)
		{
			$this->setValue(null);
		}
		
		return parent::isValid();
	}	
}
