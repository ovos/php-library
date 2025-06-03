<?php
declare(strict_types=1);

namespace Ovos\Store\Mysql\Query;

/**
 * Condition
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Condition
{
	/**#@+
	 * Condition constants
	 */
	public const string TYPE_DEFAULT = 'default';
	public const string TYPE_NESTED = 'nested';
	public const string OPERATOR_AND = 'AND';
	public const string OPERATOR_OR = 'OR';
	/**#@-*/
	
	/**
	 * @var string
	 */
	public string $type;
	
	/**
	 * @var string
	 */
	public string $operator = self::OPERATOR_AND;
	
	/**
	 * @var ?string
	 */
	public ?string $condition = null;
	
	/**
	 * @var ?array
	 */
	public ?array $nested = null;
	
	/**
	 * @param string $type
	 * @param string $operator
	 * @param ?string $condition
	 * @param ?array $nested
	 */
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
