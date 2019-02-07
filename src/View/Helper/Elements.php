<?php
declare(strict_types=1);

namespace Ovos\View\Helper;

use Ovos\View\Helper;

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
	protected $_elements = [];

	/**
	 * Classes
	 *
	 * @var array
	 */
	protected $_classes = [];

	/**
	 * @return $this
	 */
	public function elements(): self
	{
		return $this;
	}

	/**
	 * @param string $name
	 *
	 * @return $this
	 */
	public function __get(string $name): self
	{
		if(!isset($this->_elements[$name]))
		{
			$this->_elements[$name] = new self;
		}

		return $this->_elements[$name];
	}

	/**
	 * Adds class
	 *
	 * @param string $class
	 *
	 * @return $this
	 */
	public function addClass(string $class): self
	{
		$this->_classes[$class] = $class;

		return $this;
	}

	/**
	 * Ads classes
	 *
	 * @param mixed $classes
	 *
	 * @return $this
	 */
	public function addClasses($classes): self
	{
		if(\is_string($classes))
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
	public function hasAnyClasses()
	{
		return empty($this->_classes) === false;
	}

	/**
	 * Removes class
	 *
	 * @param string $class
	 *
	 * @return $this
	 */
	public function removeClass(string $class): self
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
	 * @return $this
	 */
	public function removeClasses($classes): self
	{
		if(\is_string($classes))
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