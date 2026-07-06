<?php
declare(strict_types=1);

namespace Tests\Session;

use Ovos\ArrayObject;
use Ovos\Connection\Redis as Connection;
use Ovos\Container\Inject;
use Ovos\Exception;
use Ovos\Session\Handler\RedisJson as Handler;
use Ovos\Test;
use Ovos\Test\Internal;
use Override;
use RedisException;
use Throwable;

use function bin2hex;
use function random_bytes;
use function sprintf;
use function time;
use function usleep;

/**
 * Index
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Index extends Test
{
	public const string PREFIX = 'tests:sessions:ft';
	
	#[Inject('config')]
	protected ArrayObject $config;
	
	/**
	 * RediSearch only indexes database 0 - the sessions under test live
	 * there, regardless of the configured test database
	 */
	protected ?Connection $connection = null;
	
	/**
	 * @var Handler[]
	 */
	protected array $sessions = [];
	
	public function __construct()
	{
		$connectionConfig = $this->connectionConfig(0);
		if($connectionConfig === null)
		{
			$this->setDisabled(true,
				'"connections.redis" config is missing.');
			
			return;
		}
		
		$connection = new Connection($connectionConfig);
		if($connection->connect() === false)
		{
			$this->setDisabled(true,
				'redis is not available.');
			
			return;
		}
		
		// the index requires both the RedisJSON and RediSearch modules
		try
		{
			$client = $connection->getClient();
			
			$probe = $client->rawCommand('JSON.SET',
				self::PREFIX . ':probe', '$', '{}');
			$client->del(self::PREFIX . ':probe');
			
			$client->clearLastError();
			$list = $client->rawCommand('FT._LIST');
			$search = $client->getLastError() === null;
			$client->clearLastError();
			
			if($probe === false || $search === false)
			{
				$this->setDisabled(true,
					'RedisJSON/RediSearch modules are not available.');
				
				return;
			}
		}
		catch(RedisException)
		{
			$this->setDisabled(true,
				'RedisJSON/RediSearch modules are not available.');
			
			return;
		}
		
		$this->connection = $connection;
	}
	
	protected function connectionConfig(
		int $database,
	): ?ArrayObject
	{
		$connectionConfig = $this->config
			->getPath(['connections', 'redis']);
		if($connectionConfig === null)
		{
			return null;
		}
		
		$connectionConfig = new ArrayObject(
			$connectionConfig->getArrayCopy());
		$connectionConfig->database = $database;
		
		return $connectionConfig;
	}
	
	protected function indexConfig(): ArrayObject
	{
		return new ArrayObject([
			'enabled' => true,
			'fields' => [
				'authenticated' => [
					'path' => 'auth.ok',
					'type' => 'tag',
				],
				'created' => [
					'path' => '__meta.created',
					'type' => 'numeric',
					'sortable' => true,
				],
			],
		]);
	}
	
	protected function session(): Handler
	{
		$session = new Handler(
			$this->connection,
			new Connection($this->connectionConfig(0)),
			self::PREFIX,
			new ArrayObject([
				'lifetime' => 60,
				'index' => $this->indexConfig(),
			]),
		);
		$session->open(bin2hex(random_bytes(16)));
		
		$this->sessions[] = $session;
		
		return $session;
	}
	
	/**
	 * Retries a search briefly - indexing an incoming document can lag
	 * the write by a moment
	 */
	protected function searchUntil(
		Handler $session,
		string $query,
		int $expectedTotal,
	): array
	{
		$result = ['total' => -1, 'sessions' => []];
		for($attempt = 0; $attempt < 20; $attempt++)
		{
			$result = $session->index()->search($query);
			if($result['total'] === $expectedTotal)
			{
				return $result;
			}
			usleep(50000);
		}
		
		return $result;
	}
	
	public function disabledWithoutConfig(): bool
	{
		$session = new Handler(
			$this->connection,
			new Connection($this->connectionConfig(0)),
			self::PREFIX,
			new ArrayObject(['lifetime' => 60]),
		);
		
		return $session->index() === null;
	}
	
	public function ensureCreatesTheIndex(): bool
	{
		$index = $this->session()->index();
		
		return $index->ensure() === true
			&& $index->exists() === true;
	}
	
	public function searchFindsByTag(): bool
	{
		$authenticated = $this->session();
		$authenticated->set(['auth', 'ok'], true);
		
		$anonymous = $this->session();
		$anonymous->set(['auth', 'ok'], false);
		
		$result = $this->searchUntil($authenticated,
			'@authenticated:{true}', 1);
		
		return $result['total'] === 1
			&& isset($result['sessions'][$authenticated->getSessionId()])
			&& $result['sessions'][$authenticated->getSessionId()]['auth']['ok'] === true;
	}
	
	public function searchByNumericRange(): bool
	{
		$first = $this->session();
		$first->set(['auth', 'ok'], true);
		$second = $this->session();
		$second->set(['auth', 'ok'], false);
		
		$query = sprintf('@created:[%d %d]',
			time() - 100,
			time() + 100,
		);
		$result = $this->searchUntil($first, $query, 2);
		
		return $result['total'] === 2;
	}
	
	public function countCounts(): bool
	{
		$session = $this->session();
		$session->set(['auth', 'ok'], true);
		
		$this->searchUntil($session, '*', 1);
		
		return $session->index()->count('*') === 1;
	}
	
	public function selfHealsAVanishedIndex(): bool
	{
		$session = $this->session();
		$session->set(['auth', 'ok'], true);
		$this->searchUntil($session, '*', 1);
		
		// the index vanishes behind our back (deploy, FLUSHDB, ...)
		$session->index()->drop();
		
		// the next search must recreate it and still answer
		$result = $this->searchUntil($session, '@authenticated:{true}', 1);
		
		return $result['total'] === 1;
	}
	
	public function refusesOtherDatabases(): bool
	{
		$connection = new Connection($this->connectionConfig(1));
		if($connection->connect() === false)
		{
			return false;
		}
		
		$session = new Handler(
			$connection,
			new Connection($this->connectionConfig(1)),
			self::PREFIX . ':db1',
			new ArrayObject([
				'lifetime' => 60,
				'index' => $this->indexConfig(),
			]),
		);
		
		try
		{
			$session->index()->ensure();
		}
		catch(Exception)
		{
			return true;
		}
		
		return false;
	}
	
	/**
	 * Called by the runner after each test method
	 */
	#[Internal]
	#[Override]
	public function finalize(): void
	{
		foreach($this->sessions as $session)
		{
			try
			{
				$session->destroy();
			}
			catch(Throwable)
			{
				// cleanup only
			}
		}
		$this->sessions = [];
		
		if($this->connection !== null)
		{
			$client = $this->connection->getClient();
			try
			{
				$client->clearLastError();
				$client->rawCommand('FT.DROPINDEX', self::PREFIX . ':index');
			}
			catch(RedisException)
			{
				// the index does not exist - nothing to drop
			}
			$client->clearLastError();
		}
	}
	
	/**
	 * Called by the runner after all test methods have been invoked
	 */
	#[Internal]
	#[Override]
	public function deconstruct(): void
	{
		if($this->connection === null)
		{
			return;
		}
		
		$client = $this->connection->getClient();
		if($keys = $client->keys(self::PREFIX . '*'))
		{
			$client->del($keys);
		}
	}
}
