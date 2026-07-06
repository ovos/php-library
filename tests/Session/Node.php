<?php
declare(strict_types=1);

namespace Tests\Session;

use Ovos\ArrayObject;
use Ovos\Connection\Redis as Connection;
use Ovos\Container\Inject;
use Ovos\Session\Handler\RedisJson as Handler;
use Ovos\Session\Node as SessionNode;
use Ovos\Test;
use Ovos\Test\Internal;
use Override;
use RedisException;

use function bin2hex;
use function count;
use function iterator_to_array;
use function json_encode;
use function random_bytes;

/**
 * Node
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Node extends Test
{
	public const string PREFIX = 'tests:sessions:node';
	
	#[Inject('config')]
	protected ArrayObject $config;
	
	protected ?Connection $connection = null;
	
	protected ?Handler $session = null;
	
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
	}
	
	/**
	 * Called by the runner before each test method
	 */
	#[Internal]
	#[Override]
	public function prepare(): void
	{
		$this->session = new Handler(
			$this->connection,
			new Connection($this->config->getPath(['connections', 'redis'])),
			self::PREFIX,
			new ArrayObject(['lifetime' => 60]),
		);
		$this->session->open(bin2hex(random_bytes(16)));
	}
	
	public function traversalIsLazyAndFree(): bool
	{
		// child() extends the path with zero I/O; magic traversal of
		// MISSING values also stays a node chain - either way no
		// document is ever created by traversing
		$viaChild = $this->session->node()
			->child('a')->child('b')->child('c');
		$viaMagic = $this->session->node()->a->b->c;
		
		return $viaChild->path() === ['a', 'b', 'c']
			&& $viaMagic instanceof SessionNode
			&& $viaMagic->path() === ['a', 'b', 'c']
			&& $this->session->exists() === false;
	}
	
	public function magicReadsMaterializeScalars(): bool
	{
		$node = $this->session->node();
		$node->flag = true;
		$node->user = ['name' => 'mg'];
		
		// a scalar IS the value, a container stays a lazy node
		return $node->flag === true
			&& $node->user instanceof SessionNode
			&& $node->user->name === 'mg'
			&& $node->missing instanceof SessionNode;
	}
	
	public function terminalSetAndGet(): bool
	{
		$node = $this->session->node();
		$node->basket->products->set(['x' => 1]);
		
		return $node->basket->products->get() === ['x' => 1]
			&& $node['basket']['products']->get() === ['x' => 1]
			&& $this->session->get(['basket', 'products']) === ['x' => 1];
	}
	
	public function magicWrite(): bool
	{
		$node = $this->session->node();
		$node->user = ['name' => 'mg'];
		
		return $this->session->get(['user', 'name']) === 'mg';
	}
	
	public function offsetWriteAndUnset(): bool
	{
		$node = $this->session->node();
		$node['a']['b'] = 5;
		
		$existed = isset($node['a']['b']);
		unset($node['a']['b']);
		
		return $existed === true
			&& isset($node['a']['b']) === false
			&& isset($node['a']) === true;
	}
	
	public function magicIssetAndUnset(): bool
	{
		$node = $this->session->node();
		
		$missing = isset($node->x) === false;
		$node->x = 1;
		$existed = isset($node->x);
		unset($node->x);
		
		return $missing
			&& $existed === true
			&& isset($node->x) === false;
	}
	
	public function iterationAndCount(): bool
	{
		$node = $this->session->node();
		$node->list->set([1, 2, 3]);
		
		return count($node->list) === 3
			&& iterator_to_array($node->list) === [1, 2, 3];
	}
	
	public function increments(): bool
	{
		$node = $this->session->node();
		
		// the first magic access finds nothing - a node; once the counter
		// exists, magic access materializes the number itself, so further
		// increments go through child() (or the path API)
		$first = $node->counter->increment(5);
		$second = $node->child('counter')->increment();
		
		return $first === 5
			&& $second === 6
			&& $node->counter === 6;
	}
	
	public function serializesToItsValue(): bool
	{
		$node = $this->session->node();
		$node->list->set([1, 2, 3]);
		
		return json_encode($node->list) === '[1,2,3]';
	}
	
	/**
	 * Called by the runner after each test method
	 */
	#[Internal]
	#[Override]
	public function finalize(): void
	{
		$this->session?->destroy();
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
