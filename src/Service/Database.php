<?php
declare(strict_types=1);

namespace Ovos\Service;

use Ovos\ArrayObject;
use Ovos\Service;
use Ovos\Pdo\Profiler\Pdo;
use function Ovos\services;

/**
 * Database
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Database extends Service
{
	/**
	 * @var string
	 */
	public const DEFAULT = 'database';

	/**
	 * @var string
	 */
	public const SYMBOL = 'database';

	/**
	 * @return string
	 */
	public function getSymbol(): string
	{
		return self::SYMBOL;
	}

	/**
	 * @var ArrayObject
	 */
	protected $_config;

	/**
	 * @var PDO[]
	 */
	protected $_clients;

	/**
	 * Returns database client
	 *
	 * @param string $name
	 *
	 * @return PDO
	 */
	public function get(string $name = self::DEFAULT): PDO
	{
		if(!isset($this->_clients[$name]))
		{
			$database = &$this->_clients[$name];
			$config = $this->_app->getConfig()->offsetGet($name);
			$dsn = sprintf('%s:dbname=%s;host=%s;charset=utf8', $config->type, $config->name, $config->host);
			$database = new PDO($dsn, $config->username, $config->password, [
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
				PDO::ATTR_EMULATE_PREPARES => false,
			]);

			if($this->_app->getConfig()->system->profilers->enabled)
			{
				$database->setAttribute(PDO::ATTR_STATEMENT_CLASS, ['Ovos\Pdo\Profiler\PdoStatement']);
			}

			if($this->_app->getConfig()->system->debug)
			{
				$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			}
		}

		return $this->_clients[$name];
	}
}
