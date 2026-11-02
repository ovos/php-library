<?php
declare(strict_types=1);

namespace Ovos\Store\Mysql\Query;

/**
 * SQL with positional bindings
 *
 * @author Marcin Gil <mg@ovos.at>
 */
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
