<?php
declare(strict_types=1);

namespace Ovos\View\Helper;

use Ovos\View\Helper;

use function is_string;
use function preg_split;
use function implode;

/**
 * Elements
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Elements extends Helper
{
	/**
	 * Elements
	 *
	 * @var array
	 */
	protected array $_elements = [];
	
	/**
	 * Classes
	 *
	 * @var array
	 */
	protected array $_classes = [];
	
	/**
	 * @return static
	 */
	public function elements(): static
	{
		return $this;
	}
	
	/**
	 * @param string $name
	 *
	 * @return static
	 */
	public function __get(string $name): static
	{
		if(!isset($this->_elements[$name]))
		{
			$this->_elements[$name] = new static;
		}
		
		return $this->_elements[$name];
	}
	
	/**
	 * Adds class
	 *
	 * @param string $class
	 *
	 * @return static
	 */
	public function addClass(string $class): static
	{
		$this->_classes[$class] = $class;
		
		return $this;
	}
	
	/**
	 * Ads classes
	 *
	 * @param mixed $classes
	 *
	 * @return static
	 */
	public function addClasses(mixed $classes): static
	{
		if(is_string($classes))
		{
			$classes = $this->stringToArray($classes);
		}
		
		foreach($classes as $class)
		{
			$this->_classes[$class] = $class;
		}
		
		return $this;
	}
	
	/**
	 * Has class
	 *
	 * @param string $class
	 *
	 * @return bool
	 */
	public function hasClass(string $class): bool
	{
		return isset($this->_classes[$class]);
	}
	
	/**
	 * Has any classes
	 *
	 * @return bool
	 */
	public function hasAnyClasses(): bool
	{
		return empty($this->_classes) === false;
	}
	
	/**
	 * Removes class
	 *
	 * @param string $class
	 *
	 * @return static
	 */
	public function removeClass(string $class): static
	{
		if(isset($this->_classes[$class]))
		{
			unset($this->_classes[$class]);
		}
		
		return $this;
	}
	
	/**
	 * Removes classes
	 *
	 * @param mixed $classes
	 *
	 * @return static
	 */
	public function removeClasses(mixed $classes): static
	{
		if(is_string($classes))
		{
			$classes = $this->stringToArray($classes);
		}
		
		foreach($classes as $class)
		{
			if(isset($this->_classes[$class]))
			{
				unset($this->_classes[$class]);
			}
		}
		
		return $this;
	}
	
	/**
	 * Returns classes
	 *
	 * @return array
	 */
	public function getClasses(): array
	{
		return $this->_classes;
	}
	
	/**
	 * Converts classes string to array
	 *
	 * @param string $classes
	 *
	 * @return array
	 */
	public function stringToArray(string $classes): array
	{
		return preg_split('~\s+~', $classes);
	}
	
	/**
	 * Returns classes as string
	 *
	 * @return string
	 */
	public function getClassesAsString(): string
	{
		return implode(' ', $this->_classes);
	}
	
	/**
	 * __toString
	 *
	 * @return string
	 */
	public function __toString(): string
	{
		return $this->getClassesAsString();
	}
}
