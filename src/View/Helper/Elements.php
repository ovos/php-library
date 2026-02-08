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
 * @author Marcin Gil <mg@ovos.at>
 */
class Elements extends Helper
{
	/**
	 * Elements
	 */
	protected array $elements = [];
	
	/**
	 * Classes
	 */
	protected array $classes = [];
	
	public function elements(): static
	{
		return $this;
	}
	
	public function __get(
		string $name,
	): static
	{
		if(isset($this->elements[$name]) === false)
		{
			$this->elements[$name] = new static;
		}
		
		return $this->elements[$name];
	}
	
	/**
	 * Adds class
	 */
	public function addClass(
		string $class,
	): static
	{
		$this->classes[$class] = $class;
		
		return $this;
	}
	
	/**
	 * Adds classes
	 */
	public function addClasses(
		mixed $classes,
	): static
	{
		if(is_string($classes))
		{
			$classes = $this->stringToArray($classes);
		}
		
		foreach($classes as $class)
		{
			$this->classes[$class] = $class;
		}
		
		return $this;
	}
	
	/**
	 * Has class
	 */
	public function hasClass(
		string $class,
	): bool
	{
		return isset($this->classes[$class]);
	}
	
	/**
	 * Has any classes
	 */
	public function hasAnyClasses(): bool
	{
		return empty($this->classes) === false;
	}
	
	/**
	 * Removes class
	 */
	public function removeClass(
		string $class,
	): static
	{
		if(isset($this->classes[$class]))
		{
			unset($this->classes[$class]);
		}
		
		return $this;
	}
	
	/**
	 * Removes classes
	 */
	public function removeClasses(
		mixed $classes,
	): static
	{
		if(is_string($classes))
		{
			$classes = $this->stringToArray($classes);
		}
		
		foreach($classes as $class)
		{
			if(isset($this->classes[$class]))
			{
				unset($this->classes[$class]);
			}
		}
		
		return $this;
	}
	
	/**
	 * Returns classes
	 */
	public function getClasses(): array
	{
		return $this->classes;
	}
	
	/**
	 * Converts classes string to array
	 */
	public function stringToArray(
		string $classes,
	): array
	{
		return preg_split('~\s+~', $classes);
	}
	
	/**
	 * Returns classes as string
	 */
	public function getClassesAsString(): string
	{
		return implode(' ', $this->classes);
	}
	
	public function __toString(): string
	{
		return $this->getClassesAsString();
	}
}
