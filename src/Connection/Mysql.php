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

use function ini_set;
use function sprintf;

/**
 * Mysql
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Mysql extends Connection
{
	/**
	 * Seconds to wait for the server to accept a connection (config
	 * `connect_timeout` overrides it) — PDO's own default is 30
	 */
	protected const int CONNECT_TIMEOUT = 5;
	
	protected ArrayObject $config;
	
	protected ?PDO $client = null;
	
	#[Override]
	public function getClient(): ?PDO
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
		if(isset($this->config->port))
		{
			$dsn.= ';port=' . (int)$this->config->port;
		}
		
		$options = [
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
			PDO::ATTR_EMULATE_PREPARES => false,
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_TIMEOUT => (int)($this->config->connect_timeout ?? static::CONNECT_TIMEOUT),
		];
		
		// opt-in: how long one read may wait for the server. mysqlnd reads it
		// when a connection opens, so it is set right before; unset, the
		// process default stands (a long report or migration is not cut by a
		// new default)
		if(isset($this->config->read_timeout))
		{
			ini_set('mysqlnd.net_read_timeout', (string)(int)$this->config->read_timeout);
		}
		
		try
		{
			if($this->profilers?->enabled)
			{
				$this->client = new ProfilerPdo($dsn,
					$this->config->username,
					$this->config->password,
					$options,
				);
				
				$this->client->setAttribute(PDO::ATTR_STATEMENT_CLASS, [
					PdoStatement::class,
				]);
				if($this->profilers->offsetExists('queries'))
				{
					Collector::$limit = $this->profilers->queries->limit;
				}
			}
			else
			{
				$this->client = new PDO($dsn,
					$this->config->username,
					$this->config->password,
					$options,
				);
			}
		}
		catch(PDOException $exception)
		{
			$this->client = null;
			$this->log($exception);
			
			return false;
		}
		
		return true;
	}
	
	public function disconnect(): bool
	{
		$this->client = null;
		
		return true;
	}
}
