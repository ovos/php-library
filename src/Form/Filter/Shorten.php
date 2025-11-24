<?php
declare(strict_types=1);

namespace Ovos\Form\Filter;

use Ovos\Form\Filter;
use Ovos\Strings;

/**
 * Shorten
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Shorten extends Filter
{
	/**
	 * @var int
	 */
	protected int $_length;
	
	/**
	 * @var string
	 */
	protected string $_ending;
	
	/**
	 * @param int $length
	 * @param string $ending
	 */
	public function __construct(int $length, string $ending = '...')
	{
		$this->_length = $length;
		$this->_ending = $ending;
	}
	
	/**
	 * @param int $length
	 *
	 * @return static
	 */
	public function setLength(int $length): static
	{
		$this->_length = $length;
	
		return $this;
	}
	
	/**
	 * @return int
	 */
	public function getLength(): int
	{
		return $this->_length;
	}
	
	/**
	 * @param string $ending
	 *
	 * @return static
	 */
	public function setEnding(string $ending): static
	{
		$this->_ending = $ending;
		
		return $this;
	}
	
	/**
	 * @return string
	 */
	public function getEnding(): string
	{
		return $this->_ending;
	}
	
	/**
	 * @param mixed $value
	 *
	 * @return ?string
	 */
	public function filter(mixed $value): ?string
	{
		if($value === null)
		{
			return null;
		}
		
		return Strings::shorten($value, $this->getLength(), $this->getEnding());
	}
}
