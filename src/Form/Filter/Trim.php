<?php
declare(strict_types=1);

namespace Ovos\Form\Filter;

use Ovos\Form\Filter;

use function trim;

/**
 * Trim
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Trim extends Filter
{
	/**
	 * @var string
	 */
	protected string $_characterMask;
	
	/**
	 * @param string $characterMask
	 */
	public function __construct(string $characterMask = " \t\n\r\0\x0B")
	{
		$this->setCharacterMask($characterMask);
	}
	
	/**
	 * @param string $characterMask
	 *
	 * @return static
	 */
	public function setCharacterMask(string $characterMask): static
	{
		$this->_characterMask = $characterMask;
		
		return $this;
	}
	
	/**
	 * @return string
	 */
	public function getCharacterMask(): string
	{
		return $this->_characterMask;
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
		
		return trim($value, $this->getCharacterMask());
	}
}
