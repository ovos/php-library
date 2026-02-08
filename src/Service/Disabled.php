<?php
declare(strict_types=1);

namespace Ovos\Service;

use Ovos\Service;

/**
 * Disabled
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Disabled extends Service
{
	public const string SYMBOL = 'disabled';
	
	protected bool $enabled = false;
	
	public function __call(
		string $name,
		array $arguments,
	): void
	{
		// do nothing
	}
	
	public static function __callStatic(
		string $name,
		array $arguments,
	): void
	{
		// do nothing
	}
	
	public function __get(
		string $name,
	): void
	{
		// do nothing
	}
	
	public function __set(
		string $name,
		mixed $value,
	): void
	{
		// do nothing
	}
	
	public function __isset(
		string $name,
	): bool
	{
		// do nothing
		return false;
	}
}
