<?php
declare(strict_types=1);

namespace Ovos\Pdo;

/**
 * Expression
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Expression
{
	protected string $value;
	
	public function __construct(
		string $value,
	)
	{
		$this->value = $value;
	}
	
	public function __toString(): string
	{
		return $this->value;
	}
}
