<?php
declare(strict_types=1);

namespace Ovos\Connection;

use Ovos\ArrayObject;
use Ovos\Connection;
use Ovos\Pdo\Profiler\Collector;
use Ovos\Pdo\Profiler\Pdo as ProfilerPdo;
use Ovos\Pdo\Profiler\PdoStatement;
use Override;
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
	protected ArrayObject $config;
	
	protected ?ProfilerPdo $client = null;
	
	#[Override]
	public function getClient(): ?ProfilerPdo
	{
		return parent::getClient();
	}
	
	/**
	 * Returns database client
	 */
	public function connect(): bool
	{
		$dsn = sprintf(
			'mysql:dbname=%s;host=%s;charset=utf8',
			$this->config->database,
			$this->config->host,
		);
		
		try
		{
			$this->client = new ProfilerPdo($dsn,
				$this->config->username,
				$this->config->password,
				[
					PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
					PDO::ATTR_EMULATE_PREPARES => false,
					PDO::ATTR_ERRMODE => Pdo::ERRMODE_EXCEPTION,
				],
			);
		}
		catch(PDOException $exception)
		{
			$this->client = null;
			$this->logger->log
			(
				$exception,
			);
			
			return false;
		}
		
		if($this->profilers->enabled)
		{
			$this->client->setAttribute(PDO::ATTR_STATEMENT_CLASS, [
				PdoStatement::class,
			]);
			if($this->profilers->offsetExists('queries'))
			{
				Collector::$limit = $this->profilers->queries->limit;
			}
		}
		
		return true;
	}
	
	public function disconnect(): bool
	{
		$this->client = null;
		
		return true;
	}
}
