<?php
declare(strict_types=1);

namespace Ovos\Store\Mysql\Query;

/** SQL with positional bindings. */
final class Fragment
{
	public readonly array $values;

	public function __construct(
		public readonly string $sql,
		mixed ...$values,
	)
	{
		$this->values = array_values($values);
	}
}
