<?php
declare(strict_types=1);

namespace Ovos\Pdo;

/**
 * Expression
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Expression
{
	/**
	 * @var string
	 */
	protected string $_value;
	
	/**
	 * @param string $value
	 */
	public function __construct(string $value)
	{
		$this->_value = $value;
	}
	
	/**
	 * @return string
	 */
	public function __toString(): string
	{
		return $this->_value;
	}
}
