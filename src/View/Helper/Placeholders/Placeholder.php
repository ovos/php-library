<?php
declare(strict_types=1);

namespace Ovos\View\Helper\Placeholders;
use function ob_start;
use function ob_get_clean;

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
	 * @var array
	 */
	protected array $_unique = [];

	/**
	 * @param null|string|bool|int $value
	 * @param string $placement
	 * @param bool $unique
	 *
	 * @return self
	 */
	public function set(
		null|string|bool|int $value,
		string $placement = self::PLACEMENT_REPLACE,
		bool $unique = false,
	): self
	{
		// if uniqness is required, check if the value was not already set on this placeholder
		if($this->_unique
			&& array_search($value, $this->_unique, true))
		{
			return $this;
		}
	
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
		
		// if uniqness is required, store this value for future comparisons
		if($this->_unique)
		{
			$this->_unique[] = $value;
		}

		return $this;
	}

	/**
	 * @param null|string|bool|int $value
	 * @param bool $unique
	 *
	 * @return self
	 */
	public function prepend(
		null|string|bool|int $value,
		bool $unique = false,
	): self
	{
		$this->set($value, self::PLACEMENT_PREPEND, $unique);

		return $this;
	}

	/**
	 * @param  null|string|bool|int $value
	 *
	 * @return self
	 */
	public function append(
		null|string|bool|int $value,
		bool $unique = false,
	): self
	{
		$this->set($value, self::PLACEMENT_APPEND, $unique);

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
	 * @param bool $unique
	 */
	public function captureEnd(
		string $placement = self::PLACEMENT_PREPEND,
		bool $unique = false,
	): void
	{
		$this->set(ob_get_clean(), $placement, $unique);
	}

	/**
	 * @return null|string|bool|int
	 */
	public function getValue(): null|string|bool|int
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
