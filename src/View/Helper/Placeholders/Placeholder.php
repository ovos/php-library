<?php
declare(strict_types=1);

namespace Ovos\View\Helper\Placeholders;

/**
 * Placeholder
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Placeholder
{
	/**#@+
	 * Placements
	 */
	public const PLACEMENT_REPLACE = 'replace';
	public const PLACEMENT_PREPEND = 'prepend';
	public const PLACEMENT_APPEND = 'append';
	/**#@-*/

	/**
	 * @var null|string|bool|int
	 */
	protected null|string|bool|int $_value = null;

	/**
	 * @param null|string|bool|int $value
	 * @param string $placement
	 *
	 * @return $this
	 */
	public function set($value, string $placement = self::PLACEMENT_REPLACE): self
	{
		switch($placement)
		{
			case self::PLACEMENT_REPLACE:
				$this->_value = $value;
				break;
			case self::PLACEMENT_PREPEND:
				$this->_value = $value . $this->_value;
				break;
			case self::PLACEMENT_APPEND:
				$this->_value.= $value;
				break;
		}

		return $this;
	}

	/**
	 * @param null|string|bool|int $value
	 *
	 * @return $this
	 */
	public function prepend(null|string|bool|int $value): self
	{
		$this->set($value, self::PLACEMENT_PREPEND);

		return $this;
	}

	/**
	 * @param  null|string|bool|int $value
	 *
	 * @return $this
	 */
	public function append( null|string|bool|int $value): self
	{
		$this->set($value, self::PLACEMENT_APPEND);

		return $this;
	}

	/**
	 */
	public function captureStart(): void
	{
		ob_start();
	}

	/**
	 * @param string $placement
	 */
	public function captureEnd(string $placement = self::PLACEMENT_PREPEND): void
	{
		$this->set(ob_get_clean(), $placement);
	}

	/**
	 * @return null|string|bool|int
	 */
	public function getValue()
	{
		return $this->_value;
	}

	/**
	 * @return string
	 */
	public function __toString(): string
	{
		return (string)$this->getValue();
	}
}
