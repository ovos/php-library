<?php
declare(strict_types=1);

namespace Ovos\Store\Mysql\Query;

/**
 * Condition
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Condition
{
	// Types
	public const string TYPE_DEFAULT = 'default';
	public const string TYPE_NESTED = 'nested';
	
	// Operators
	public const string OPERATOR_AND = 'AND';
	public const string OPERATOR_OR = 'OR';
	
	public string $type;
	
	public string $operator = self::OPERATOR_AND;
	
	public ?string $condition = null;
	
	public ?array $nested = null;
	
	public function __construct(
		string $type,
		string $operator,
		?string $condition = null,
		?array $nested = null,
	)
	{
		$this->type = $type;
		$this->operator = $operator;
		$this->condition = $condition;
		$this->nested = $nested;
	}
}
