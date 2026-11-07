<?php
declare(strict_types=1);

namespace Ovos\Test\Cache\Store;

use Redis as BaseRedis;
use RedisException;
use Closure;

use function count;
use function in_array;
use function is_array;

/**
 * CountingClient
 *
 * A phpredis client that counts its round trips, for a probe of a store's
 * batch (see TraitBatch): a command sent on its own is one, a pipeline (or
 * a transaction) is one at its exec(), whatever it carries. What each round
 * trip carried is kept in $sent (resetCount() starts over), so a rule can
 * tell a pipeline of reads from as many single reads. Only the commands the
 * cache stores send are counted
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class CountingClient extends BaseRedis
{
	/**
	 * The round trips made since the last resetCount()
	 */
	public int $roundTrips = 0;
	
	/**
	 * What each round trip carried: one command, or a pipeline's
	 *
	 * @var list<list<string>>
	 */
	public array $sent = [];
	
	/**
	 * The commands queued in a pipeline not sent yet
	 *
	 * @var list<string>
	 */
	protected array $queued = [];
	
	/**
	 * Runs once right before the next SET NX - a lock - is queued or sent:
	 * another process acts between a read and the lock taken after it
	 */
	public ?Closure $beforeLock = null;
	
	/**
	 * Runs once right before the next FCALL is sent: another process acts
	 * between a moment its caller took and the call
	 */
	public ?Closure $beforeCall = null;
	
	/**
	 * How many reads fail from now on, as on a connection that timed out: an
	 * HMGET sent on its own throws, and so does the exec() of a pipeline that
	 * holds one (the pipeline discarded)
	 */
	public int $failingReads = 0;
	
	/**
	 * How many reads answer false from now on, as an error reply (BUSY,
	 * LOADING, MASTERDOWN) makes them, without throwing: an HMGET sent on its
	 * own, or each HMGET of a pipeline
	 */
	public int $erringReads = 0;
	
	/**
	 * How many reads of a stream (XRANGE sent on its own) answer false from
	 * now on, as an error reply makes them, without throwing
	 */
	public int $erringRanges = 0;
	
	public function resetCount(): void
	{
		$this->roundTrips = 0;
		$this->sent = [];
	}
	
	/**
	 * The commands sent on their own (not in a pipeline), by name
	 *
	 * @return list<string>
	 */
	public function singles(): array
	{
		$singles = [];
		foreach($this->sent as $commands)
		{
			if(count($commands) === 1)
			{
				$singles[] = $commands[0];
			}
		}
		
		return $singles;
	}
	
	/**
	 * A command sent on its own is a round trip; in a pipeline it waits for
	 * its exec()
	 */
	protected function counted(
		string $command,
	): void
	{
		if($this->getMode() === BaseRedis::ATOMIC)
		{
			$this->roundTrips++;
			$this->sent[] = [$command];
			
			return;
		}
		
		$this->queued[] = $command;
	}
	
	public function exec(): BaseRedis|array|false
	{
		if($this->failingReads > 0
			&& in_array('hMget', $this->queued, true))
		{
			$this->failingReads--;
			$this->queued = [];
			$this->discard();
			
			throw new RedisException('read error on connection');
		}
		
		$this->roundTrips++;
		$this->sent[] = $this->queued;
		$queued = $this->queued;
		$this->queued = [];
		
		$replies = parent::exec();
		if(is_array($replies))
		{
			foreach($queued as $index => $command)
			{
				if($command === 'hMget' && $this->erringReads > 0)
				{
					$this->erringReads--;
					$replies[$index] = false;
				}
			}
		}
		
		return $replies;
	}
	
	public function hMget(
		string $key,
		array $fields,
	): BaseRedis|array|false
	{
		if($this->failingReads > 0
			&& $this->getMode() === BaseRedis::ATOMIC)
		{
			$this->failingReads--;
			
			throw new RedisException('read error on connection');
		}
		
		if($this->erringReads > 0
			&& $this->getMode() === BaseRedis::ATOMIC)
		{
			$this->erringReads--;
			$this->roundTrips++;
			$this->sent[] = ['hMget'];
			
			return false;
		}
		
		$this->counted('hMget');
		
		return parent::hMget($key, $fields);
	}
	
	public function set(
		string $key,
		mixed $value,
		mixed $options = null,
	): BaseRedis|string|bool
	{
		if($this->beforeLock !== null
			&& is_array($options)
			&& in_array('NX', $options, true))
		{
			$beforeLock = $this->beforeLock;
			$this->beforeLock = null;
			$beforeLock();
		}
		$this->counted('set');
		
		return parent::set($key, $value, $options);
	}
	
	public function time(): BaseRedis|array
	{
		$this->counted('time');
		
		return parent::time();
	}
	
	public function pttl(
		string $key,
	): BaseRedis|int|false
	{
		$this->counted('pttl');
		
		return parent::pttl($key);
	}
	
	public function exists(
		mixed $key,
		mixed ...$other_keys,
	): BaseRedis|int|bool
	{
		$this->counted('exists');
		
		return parent::exists($key, ...$other_keys);
	}
	
	public function fcall(
		string $fn,
		array $keys = [],
		array $args = [],
	): mixed
	{
		if($this->beforeCall !== null)
		{
			$beforeCall = $this->beforeCall;
			$this->beforeCall = null;
			$beforeCall();
		}
		$this->counted('fcall');
		
		return parent::fcall($fn, $keys, $args);
	}
	
	public function fcall_ro(
		string $fn,
		array $keys = [],
		array $args = [],
	): mixed
	{
		$this->counted('fcall_ro');
		
		return parent::fcall_ro($fn, $keys, $args);
	}
	
	public function rawCommand(
		string $command,
		mixed ...$args,
	): mixed
	{
		$this->counted('rawCommand ' . $command);
		
		return parent::rawCommand($command, ...$args);
	}
	
	public function xRange(
		string $key,
		string $start,
		string $end,
		int $count = -1,
	): BaseRedis|array|bool
	{
		if($this->erringRanges > 0
			&& $this->getMode() === BaseRedis::ATOMIC)
		{
			$this->erringRanges--;
			$this->roundTrips++;
			$this->sent[] = ['xRange'];
			
			return false;
		}
		
		$this->counted('xRange');
		
		return parent::xRange($key, $start, $end, $count);
	}
	
	public function xRevRange(
		string $key,
		string $end,
		string $start,
		int $count = -1,
	): BaseRedis|array|bool
	{
		$this->counted('xRevRange');
		
		return parent::xRevRange($key, $end, $start, $count);
	}
	
	public function del(
		array|string $key,
		string ...$other_keys,
	): BaseRedis|int|false
	{
		$this->counted('del');
		
		return parent::del($key, ...$other_keys);
	}
	
	public function unlink(
		array|string $key,
		string ...$other_keys,
	): BaseRedis|int|false
	{
		$this->counted('unlink');
		
		return parent::unlink($key, ...$other_keys);
	}
	
	public function hGet(
		string $key,
		string $member,
	): mixed
	{
		$this->counted('hGet');
		
		return parent::hGet($key, $member);
	}
	
	public function hExists(
		string $key,
		string $field,
	): BaseRedis|bool
	{
		$this->counted('hExists');
		
		return parent::hExists($key, $field);
	}
	
	public function hGetAll(
		string $key,
	): BaseRedis|array|false
	{
		$this->counted('hGetAll');
		
		return parent::hGetAll($key);
	}
	
	public function get(
		string $key,
	): mixed
	{
		$this->counted('get');
		
		return parent::get($key);
	}
	
	public function function(
		string $operation,
		mixed ...$args,
	): BaseRedis|array|string|bool
	{
		$this->counted('function ' . $operation);
		
		return parent::function($operation, ...$args);
	}
}
