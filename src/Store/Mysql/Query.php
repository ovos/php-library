<?php
declare(strict_types=1);

namespace Ovos\Store\Mysql;

use Ovos\Exception;
use Ovos\Pdo\Expression;
use PDO;
use PDOStatement;

/**
 * Query
 * TODO
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Query
{
	/**
	 * @var Table
	 */
	protected $_table;

	/**
	 * @var Join[]
	 */
	protected $_joins;

	/**
	 * @var Where[]
	 */
	protected $_where;

	/**
	 * @var array
	 */
	protected $_params = [];
}
