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
	protected string $characterMask;
	
	public function __construct(
		string $characterMask = " \t\n\r\0\x0B",
	)
	{
		$this->setCharacterMask($characterMask);
	}
	
	public function setCharacterMask(
		string $characterMask,
	): static
	{
		$this->characterMask = $characterMask;
		
		return $this;
	}
	
	public function getCharacterMask(): string
	{
		return $this->characterMask;
	}
	
	public function filter(
		mixed $value,
	): ?string
	{
		if($value === null)
		{
			return null;
		}
		
		return trim($value, $this->getCharacterMask());
	}
}
