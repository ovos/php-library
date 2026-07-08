<?php
declare(strict_types=1);

namespace Tests\Session\Store;

use Ovos\ArrayObject;
use Ovos\Connection\Redis as Connection;
use Ovos\Container\Inject;
use Ovos\Session\Handler\RedisJson as Handler;
use Ovos\Session\Node;
use Ovos\Session\Store\Json as Store;
use Ovos\Test;
use Ovos\Test\Internal;
use Override;
use RedisException;
use Throwable;

use function bin2hex;
use function count;
use function random_bytes;

/**
 * Json
 *
 * The RedisJson store surface: the handler's semantics are covered by
 * tests/Session/Handler/RedisJson - this only checks that the Store wires
 * each method through to the handler and that the peek-based slot() behaves.
 * Self-disables when redis / the RedisJSON module is unavailable.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Json extends Test
{
	public const string PREFIX = 'tests:sessions:store:json';
	
	#[Inject('config')]
	protected ArrayObject $config;
	
	protected ?Connection $connection = null;
	
	protected ?Connection $queueConnection = null;
	
	/**
	 * @var Handler[]
	 */
	protected array $handlers = [];
	
	public function __construct()
	{
		$connectionConfig = $this->config
			->getPath(['connections', 'redis']);
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
		
		// the handler requires the RedisJSON module
		try
		{
			$client = $connection->getClient();
			$probe = $client->rawCommand('JSON.SET',
				self::PREFIX . ':probe', '$', '{}');
			if($probe === false)
			{
				$this->setDisabled(true,
					'RedisJSON module is not available.');
				
				return;
			}
			$client->del(self::PREFIX . ':probe');
		}
		catch(RedisException)
		{
			$this->setDisabled(true,
				'RedisJSON module is not available.');
			
			return;
		}
		
		$this->connection = $connection;
		$this->queueConnection = new Connection($connectionConfig);
	}
	
	/**
	 * A fresh store over a handler bound to a random id, cleaned up by
	 * finalize()
	 */
	protected function store(): Store
	{
		$handler = new Handler(
			$this->connection,
			$this->queueConnection,
			self::PREFIX,
			new ArrayObject([
				'lifetime' => 60,
				'lock' => [
					'enabled' => true,
					'lock_ttl_ms' => 3000,
					'wait_attempts' => 3,
				],
			]),
		);
		$handler->open(bin2hex(random_bytes(16)));
		
		$this->handlers[] = $handler;
		
		return new Store($handler);
	}
	
	public function delegatesReadsAndWrites(): bool
	{
		$store = $this->store();
		$store->set(['basket', 'products'], ['a' => 1]);
		
		return $store->get(['basket', 'products']) === ['a' => 1]
			&& $store->has(['basket', 'products']) === true;
	}
	
	public function delegatesIncrementAndAppend(): bool
	{
		$store = $this->store();
		
		return $store->increment(['counter'], 2) === 2
			&& $store->append(['log'], 'a') === 1
			&& $store->get(['log']) === ['a'];
	}
	
	public function delegatesRemove(): bool
	{
		$store = $this->store();
		$store->set(['a', 'b'], 1);
		$store->remove(['a', 'b']);
		
		return $store->has(['a', 'b']) === false;
	}
	
	public function delegatesJourney(): bool
	{
		$store = $this->store();
		$store->addAction('bought', ['ticket' => 42]);
		
		$journey = $store->getJourney();
		
		return count($journey) === 1
			&& $journey[0]['action'] === 'bought'
			&& $journey[0]['data']['ticket'] === 42;
	}
	
	public function delegatesGetManyAndUpdate(): bool
	{
		$store = $this->store();
		$store->set(['a'], 1);
		$store->set(['b'], 2);
		
		$many = $store->getMany([['a'], ['b']]);
		$updated = $store->update(['a'],
			static fn(mixed $value): mixed => $value + 10);
		
		return $many === ['a' => 1, 'b' => 2]
			&& $updated === 11
			&& $store->get(['a']) === 11;
	}
	
	/**
	 * The Json slot peeks: a scalar materializes as its value, a container
	 * or a missing value stays a lazy Node
	 */
	public function slotMaterializesScalarsAndKeepsContainersLazy(): bool
	{
		$store = $this->store();
		$store->set(['flag'], true);
		$store->set(['ns'], ['a' => 1]);
		
		return $store->slot('flag') === true
			&& $store->slot('ns') instanceof Node
			&& $store->slot('missing') instanceof Node;
	}
	
	public function exposesTheHandler(): bool
	{
		$store = $this->store();
		
		return $store->handler() instanceof Handler;
	}
	
	public function releaseLockDelegatesToTheHandler(): bool
	{
		$store = $this->store();
		$store->set(['v'], 'x');
		$store->getLocked(['v']);
		
		$released = $store->releaseLock(['v']);
		
		return $released === true
			&& $store->get(['v']) === 'x';
	}
	
	/**
	 * Called by the runner after each test method
	 */
	#[Internal]
	#[Override]
	public function finalize(): void
	{
		foreach($this->handlers as $handler)
		{
			try
			{
				$handler->destroy();
			}
			catch(Throwable)
			{
				// cleanup only
			}
		}
		$this->handlers = [];
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
		if($keys = $client->keys(self::PREFIX . ':*'))
		{
			$client->del($keys);
		}
	}
}
