<?php
declare(strict_types=1);

namespace Ovos\Service;

use Ovos\ArrayObject;
use Ovos\Pdo\Profiler\Collector;
use Ovos\Service;
use Ovos\Pdo\Profiler\Pdo;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Query\QueryBuilder;
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
	protected ArrayObject $_config;

	/**
	 * @var PDO[]
	 */
	protected array $_clients;

	/**
	 * @var Connection[]
	 */
	protected array $_DBALs;

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
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			]);

			/** @var ArrayObject $configProfilers */
			$configProfilers = $this->_app->getConfig()->system->profilers;
			if($configProfilers->enabled)
			{
				$database->setAttribute(PDO::ATTR_STATEMENT_CLASS, ['Ovos\Pdo\Profiler\PdoStatement']);
				if($configProfilers->offsetExists('queries'))
				{
					Collector::$limit = $configProfilers->queries->limit;
				}
			}
		}

		return $this->_clients[$name];
	}
	
	/**
	 * @param string $name
	 * 
	 * @return Connection
	 * @throws \Doctrine\DBAL\Exception
	 */
	public function getDBAL(string $name = self::DEFAULT): Connection
	{
		if(!isset($this->_DBALs[$name]))
		{
			$this->_DBALs[$name] = DriverManager::getConnection([
				'driver' => 'pdo_mysql',
				'pdo' => $this->get($name),
			]);
		}
		
		return $this->_DBALs[$name];
	}	
	
	/**
	 * @param string $name
	 * 
	 * @return QueryBuilder
	 */
	public function getQueryBuilder(string $name = self::DEFAULT): QueryBuilder
	{
		return $this->getDBAL($name)->createQueryBuilder();
	}
}
