<?php
declare(strict_types=1);

namespace Ovos\Service;

use Ovos\ArrayObject;
use Ovos\Pdo\Profiler\Collector;
use Ovos\Service;
use Ovos\Pdo\Profiler\Pdo as OvosPdo;
use PDO;
use Ovos\Pdo\Profiler\PdoStatement;

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
	public const string DEFAULT = 'database';
	
	/**
	 * @var string
	 */
	public const string SYMBOL = 'database';
	
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
	 * @var OvosPdo[]
	 */
	protected array $_clients;
	
	/**
	 * Returns database client
	 *
	 * @param string $name
	 *
	 * @return OvosPdo
	 */
	public function get(string $name = self::DEFAULT): OvosPdo
	{
		if(isset($this->_clients[$name]) === false)
		{
			$database = &$this->_clients[$name];
			$config = $this->_app->getConfig()->offsetGet($name);
			$dsn = sprintf('%s:dbname=%s;host=%s;charset=utf8', $config->type, $config->name, $config->host);
			$database = new OvosPdo($dsn, $config->username, $config->password, [
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
				PDO::ATTR_EMULATE_PREPARES => false,
				PDO::ATTR_ERRMODE => Pdo::ERRMODE_EXCEPTION,
			]);
			
			/** @var ArrayObject $configProfilers */
			$configProfilers = $this->_app->getConfig()->system->profilers;
			if($configProfilers->enabled)
			{
				$database->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PdoStatement::class]);
				if($configProfilers->offsetExists('queries'))
				{
					Collector::$limit = $configProfilers->queries->limit;
				}
			}
		}
		
		return $this->_clients[$name];
	}
}
