<?php
declare(strict_types=1);

namespace Ovos\Store\Mysql;

use Ovos\Exception;
use Ovos\Pdo\Expression;
use PDO;
use PDOStatement;

/**
 * Select
 * TODO
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Select extends Query
{
	/**
	 * @var string
	 */
	protected $_select;

	/**
	 * @var string
	 */
	protected $_order;

	/**
	 * @var string
	 */
	protected $_direction;

	/**
	 * @var int
	 */
	protected $_limit;

	/**
	 * @var int
	 */
	protected $_offset;
}
