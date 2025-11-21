<?php
declare(strict_types=1);

namespace Benchmarks\Store\Redis;

use Ovos\ArrayObject;
use Ovos\Benchmark;
use Ovos\Connection\Redis as Connection;
use Ovos\Connections;
use Ovos\Container\ArrayObject as InjectArrayObject;
use Ovos\Container\Inject;
use Ovos\Store\KeyValue;
use Ovos\Store\Redis as Store;
use Ovos\Test\Internal;
use Ovos\Test\Parallel;
use RedisException;

use function Ovos\config;
use function sprintf;

/**
 * Queue
 *
 * @package Bechmarks
 * @author Marcin Gil <mg@ovos.at>
 */
class Queue extends Benchmark
{
	/**
	 * @var string
	 */
	public const string KEY_ITEM = 'item';
	
	/**
	 * @var string
	 */
	public const string KEY_ITEM_COUNTER = 'item:counter';
	
	/**
	 * @var int
	 */
	public const int CLIENTS = 100;
	
	/**
	 * @var ArrayObject
	 */
	#[Inject('config')]
	#[InjectArrayObject('cache')]
	protected ArrayObject $_config;
	
	/**
	 * @var ?Connection
	 */
	protected ?Connection $_connection = null;
	
	/**
	 * @var ?Store
	 */
	protected ?Store $_store = null;
	
	public function __construct()
	{
		$this->_connection = $this->_container
			->getClass(Connections::class)
			->get($this->_config->persistent->connection);
		
		if($this->_connection->connect() === false)
		{
			throw new RedisException
			(
				sprintf('Could not connect to redis server "%s" on port "%s".',
					$this->_store->getConfig()->host,
					$this->_store->getConfig()->port,
				)
			);
		}
	}
	
	protected function _initStore(): void
	{
		$this->_store = new Store
		(
			$this->_connection,
			$this->_config->persistent,
			$this->_config->prefix,
			KeyValue::GROUP_BENCHMARKS,
		);
	}
	
	/**
	 * Called by the runner before each test method
	 */
	#[Internal]
	public function prepare(): void
	{
		$this->_initStore();
	}
	
	public function queue(): bool
	{
		$phpBinary = config()->getPath(['cli', 'executable']);
		$phpBinary = $phpBinary ?? 'php';
		$command = sprintf('%s %s %s', $phpBinary,
			dirname(__DIR__, 3) . DIRECTORY_SEPARATOR
			. 'tests' . DIRECTORY_SEPARATOR
			. 'Store' . DIRECTORY_SEPARATOR
			. 'Redis' . DIRECTORY_SEPARATOR
			. 'Queue' . DIRECTORY_SEPARATOR
			. 'QueueClient.file.php',
			KeyValue::GROUP_BENCHMARKS,
		);
		
		try
		{
			Parallel::run($command, self::CLIENTS);
			
			$id = $this->_store->prefix(self::KEY_ITEM_COUNTER,
				$this->_store->getType()
			);
			
			$count = $this->_store->getClient()->get($id);
			
			return (int)$count === 1;
		}
		finally
		{
			$this->_store->delete(self::KEY_ITEM);
			$this->_store->delete(self::KEY_ITEM_COUNTER);
		}
	}
	
	/**
	 * Called by the runner after each test method
	 */
	#[Internal]
	public function finalize(): void
	{
		$this->_store->clear();
	}
	
	/**
	 * Called by the runner after all test methods have been invoked
	 */
	#[Internal]
	public function deconstruct(): void
	{
		$this->_connection->disconnect();
	}
}
