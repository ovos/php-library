<?php
declare(strict_types=1);

namespace Ovos\Service;

use Ovos\ArrayObject;
use Ovos\Service;

/**
 * Disabled
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Disabled extends Service
{
	/**
	 * @var string
	 */
	public const SYMBOL = 'disabled';

	/**
	 * @var bool
	 */
	protected bool $_enabled = false;
	
	/**
	 * @return string
	 */
	public function getSymbol(): string
	{
		return self::SYMBOL;
	}

	/**
	 * @param string $name
	 * @param array $arguments
	 */
	public function __call(string $name, array $arguments): void
	{
		// do nothing
	}
	
	/**
	 * @param string $name
	 * @param array $arguments
	 */
	public static function __callStatic(string $name, array $arguments): void
	{
		// do nothing
	}

	/**
	 * @param string $name
	 */
	public function __get($name): void
	{
		// do nothing	
	}

	/**
	 * @param string $name
	 * @param mixed $value
	 */
	public function __set($name, $value): void
	{
		// do nothing
	}
	
	/**
	 * @param string $name
	 */
	public function __isset($name): bool
	{
		// do nothing
		return false;
	}
}
