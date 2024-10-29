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
	 * @return self
	 */
	public function setCharacterMask(string $characterMask): self
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
	 * @return mixed
	 */
	public function filter(mixed $value): string
	{
		return trim($value, $this->getCharacterMask());
	}
}
