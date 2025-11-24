<?php
declare(strict_types=1);

namespace Benchmarks\Store\Redis;

use Ovos\ArrayObject;
use Ovos\Benchmark;
use Ovos\Container\Inject;
use Ovos\Store\KeyValue;
use Ovos\Store\Redis as Store;
use Ovos\Test\Internal;
use Ovos\Test\Parallel;
use Ovos\Test\Store\TraitRedis;

use function sprintf;

/**
 * Queue
 *
 * @package Bechmarks
 * @author Marcin Gil <mg@ovos.at>
 */
class Queue extends Benchmark
{
	use TraitRedis;
	
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
	 * @var ?Store
	 */
	protected ?Store $_store = null;
	
	/**
	 * @var ArrayObject
	 */
	#[Inject('config')]
	protected ArrayObject $_config;
	
	public function __construct()
	{
		if($this->_cacheConfig->getPath(['persistent', 'queue', 'enabled']) !== true)
		{
			$this->setIsDisabled(true,
				sprintf('"queue" is not enabled in cache config.')
			);
			
			return;
		}
		
		$this->_group = KeyValue::GROUP_BENCHMARKS;
		$this->_store = $this->_getStore(Store::class);
	}
	
	public function queue(): bool
	{
		$phpBinary = $this->_config->getPath(['cli', 'executable']);
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
	#[Override]
	public function deconstruct(): void
	{
		$this->_connection->disconnect();
	}
}
