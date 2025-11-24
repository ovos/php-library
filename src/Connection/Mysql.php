<?php
declare(strict_types=1);

namespace Ovos\Connection;

use Ovos\ArrayObject;
use Ovos\Connection;
use Ovos\Pdo\Profiler\Collector;
use Ovos\Pdo\Profiler\Pdo as ProfilerPdo;
use Ovos\Pdo\Profiler\PdoStatement;
use PDO;
use PDOException;

use function sprintf;

/**
 * Mysql
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Mysql extends Connection
{
	/**
	 * @var ArrayObject
	 */
	protected ArrayObject $_config;
	
	/**
	 * @var ?ProfilerPdo
	 */
	protected ?ProfilerPdo $_client = null;
	
	/**
	 * @return ?ProfilerPdo
	 */
	public function getClient(): ?ProfilerPdo
	{
		return $this->_client;
	}
	
	/**
	 * @return ?ProfilerPdo
	 */
	public function getConnectedClient(): ?ProfilerPdo
	{
		return parent::getConnectedClient();
	}
	
	/**
	 * Returns database client
	 *
	 * @return bool
	 */
	public function connect(): bool
	{
		$dsn = sprintf('mysql:dbname=%s;host=%s;charset=utf8',
			$this->_config->database, $this->_config->host);
		
		try
		{
			$this->_client = new ProfilerPdo($dsn,
				$this->_config->username,
				$this->_config->password,
				[
					PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
					PDO::ATTR_EMULATE_PREPARES => false,
					PDO::ATTR_ERRMODE => Pdo::ERRMODE_EXCEPTION,
				],
			);
		}
		catch(PDOException $exception)
		{
			$this->_client = null;
			$this->_logger->log
			(
				$exception
			);
			
			return false;
		}
		
		if($this->_profilers->enabled)
		{
			$this->_client->setAttribute(PDO::ATTR_STATEMENT_CLASS, [
				PdoStatement::class,
			]);
			if($this->_profilers->offsetExists('queries'))
			{
				Collector::$limit = $this->_profilers->queries->limit;
			}
		}
		
		return true;
	}
	
	/**
	 * @return bool
	 */
	public function disconnect(): bool
	{
		$this->_client = null;
		
		return true;
	}
}
