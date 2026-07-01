<?php
declare(strict_types=1);

namespace Ovos\Redis\Profiler;

use Closure;
use Ovos\Measurement;
use Redis as BaseRedis;
use RedisException;

use function array_merge;

/**
 * Client
 *
 * A profiling \Redis subclass: each command is timed and recorded into the
 * Redis profiler Collector, then delegated to the real client. Used only when
 * profilers are enabled (Connection\Redis::connect() instantiates the plain
 * \Redis otherwise), so production carries zero overhead.
 *
 * The app talks to redis through the raw phpredis client (Console\Redis), which
 * bypasses the Connection wrapper's slow-log profiling — these overrides are
 * what make those calls visible in the profiler. fcall/fcall_ro are deliberately
 * NOT overridden: the cache already profiles them via RedisCommon::slowLog().
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Client extends BaseRedis
{
	/**
	 * Times a command and records it, unless we are inside MULTI/PIPELINE — there
	 * commands are queued and return $this, so only exec() actually runs them and
	 * per-command timing is meaningless.
	 *
	 * @param string[]|int[] $keys
	 */
	protected function profile(
		string $command,
		array $keys,
		Closure $function,
	): mixed
	{
		if($this->getMode() !== BaseRedis::ATOMIC)
		{
			return $function();
		}
		
		$measurement = new Measurement;
		$measurement->start();
		
		try
		{
			$result = $function();
		}
		catch(RedisException $exception)
		{
			$measurement->stop();
			Collector::getInstance()->setCommand($command, $keys, [], $measurement);
			
			throw $exception;
		}
		
		$measurement->stop();
		Collector::getInstance()->setCommand($command, $keys, [], $measurement);
		
		return $result;
	}
	
	// ----- strings / keys -----
	
	public function get(
		string $key,
	): mixed
	{
		return $this->profile('get', [$key],
			fn() => parent::get($key),
		);
	}
	
	public function set(
		string $key,
		mixed $value,
		mixed $options = null,
	): string|BaseRedis|bool
	{
		return $this->profile('set', [$key],
			fn() => parent::set($key, $value, $options),
		);
	}
	
	public function getDel(
		string $key,
	): string|BaseRedis|bool
	{
		return $this->profile('getDel', [$key],
			fn() => parent::getDel($key),
		);
	}
	
	public function incr(
		string $key,
		int $by = 1,
	): int|BaseRedis|false
	{
		return $this->profile('incr', [$key],
			fn() => parent::incr($key, $by),
		);
	}
	
	public function expire(
		string $key,
		int $timeout,
		?string $mode = null,
	): BaseRedis|bool
	{
		return $this->profile('expire', [$key],
			fn() => parent::expire($key, $timeout, $mode),
		);
	}
	
	public function persist(
		string $key,
	): BaseRedis|bool
	{
		return $this->profile('persist', [$key],
			fn() => parent::persist($key),
		);
	}
	
	public function exists(
		mixed $key,
		mixed ...$other_keys,
	): int|BaseRedis|bool
	{
		return $this->profile('exists', array_merge([$key], $other_keys),
			fn() => parent::exists($key, ...$other_keys),
		);
	}
	
	public function del(
		array|string $key,
		string ...$other_keys,
	): int|BaseRedis|false
	{
		return $this->profile('del', array_merge((array)$key, $other_keys),
			fn() => parent::del($key, ...$other_keys),
		);
	}
	
	public function unlink(
		array|string $key,
		string ...$other_keys,
	): int|BaseRedis|false
	{
		return $this->profile('unlink', array_merge((array)$key, $other_keys),
			fn() => parent::unlink($key, ...$other_keys),
		);
	}
	
	// ----- hashes -----
	
	public function hGet(
		string $key,
		string $member,
	): mixed
	{
		return $this->profile('hGet', [$key],
			fn() => parent::hGet($key, $member),
		);
	}
	
	public function hSet(
		string $key,
		mixed ...$fields_and_vals,
	): int|BaseRedis|false
	{
		return $this->profile('hSet', [$key],
			fn() => parent::hSet($key, ...$fields_and_vals),
		);
	}
	
	public function hSetNx(
		string $key,
		string $field,
		mixed $value,
	): BaseRedis|bool
	{
		return $this->profile('hSetNx', [$key],
			fn() => parent::hSetNx($key, $field, $value),
		);
	}
	
	public function hMSet(
		string $key,
		array $fieldvals,
	): BaseRedis|bool
	{
		return $this->profile('hMSet', [$key],
			fn() => parent::hMSet($key, $fieldvals),
		);
	}
	
	public function hMget(
		string $key,
		array $fields,
	): BaseRedis|array|false
	{
		return $this->profile('hMGet', [$key],
			fn() => parent::hMget($key, $fields),
		);
	}
	
	public function hGetAll(
		string $key,
	): array|BaseRedis|false
	{
		return $this->profile('hGetAll', [$key],
			fn() => parent::hGetAll($key),
		);
	}
	
	public function hIncrBy(
		string $key,
		string $field,
		int $value,
	): int|BaseRedis|false
	{
		return $this->profile('hIncrBy', [$key],
			fn() => parent::hIncrBy($key, $field, $value),
		);
	}
	
	public function hExists(
		string $key,
		string $field,
	): BaseRedis|bool
	{
		return $this->profile('hExists', [$key],
			fn() => parent::hExists($key, $field),
		);
	}
	
	// ----- streams -----
	
	public function xAdd(
		string $key,
		string $id,
		array $values,
		int $maxlen = 0,
		bool $approx = false,
		bool $nomkstream = false,
	): BaseRedis|string|false
	{
		return $this->profile('xAdd', [$key],
			fn() => parent::xAdd($key, $id, $values, $maxlen, $approx, $nomkstream),
		);
	}
	
	public function xRead(
		array $streams,
		int $count = -1,
		int $block = -1,
	): BaseRedis|array|bool
	{
		return $this->profile('xRead', array_keys($streams),
			fn() => parent::xRead($streams, $count, $block),
		);
	}
	
	public function xReadGroup(
		string $group,
		string $consumer,
		array $streams,
		int $count = 1,
		int $block = 1,
	): BaseRedis|array|bool
	{
		return $this->profile('xReadGroup', array_keys($streams),
			fn() => parent::xReadGroup($group, $consumer, $streams, $count, $block),
		);
	}
	
	public function xRevRange(
		string $key,
		string $end,
		string $start,
		int $count = -1,
	): BaseRedis|array|bool
	{
		return $this->profile('xRevRange', [$key],
			fn() => parent::xRevRange($key, $end, $start, $count),
		);
	}
	
	public function xRange(
		string $key,
		string $start,
		string $end,
		int $count = -1,
	): BaseRedis|array|bool
	{
		return $this->profile('xRange', [$key],
			fn() => parent::xRange($key, $start, $end, $count),
		);
	}
	
	public function xLen(
		string $key,
	): BaseRedis|int|false
	{
		return $this->profile('xLen', [$key],
			fn() => parent::xLen($key),
		);
	}
	
	public function xAck(
		string $key,
		string $group,
		array $ids,
	): int|false
	{
		return $this->profile('xAck', [$key],
			fn() => parent::xAck($key, $group, $ids),
		);
	}
	
	public function xClaim(
		string $key,
		string $group,
		string $consumer,
		int $min_idle,
		array $ids,
		array $options,
	): BaseRedis|array|bool
	{
		return $this->profile('xClaim', [$key],
			fn() => parent::xClaim($key, $group, $consumer, $min_idle, $ids, $options),
		);
	}
	
	public function xDel(
		string $key,
		array $ids,
	): BaseRedis|int|false
	{
		return $this->profile('xDel', [$key],
			fn() => parent::xDel($key, $ids),
		);
	}
	
	public function xGroup(
		string $operation,
		?string $key = null,
		?string $group = null,
		?string $id_or_consumer = null,
		bool $mkstream = false,
		int $entries_read = -2,
	): mixed
	{
		return $this->profile('xGroup ' . $operation, $key === null ? [] : [$key],
			fn() => parent::xGroup($operation, $key, $group, $id_or_consumer, $mkstream, $entries_read),
		);
	}
	
	public function xPending(
		string $key,
		string $group,
		?string $start = null,
		?string $end = null,
		int $count = -1,
		?string $consumer = null,
	): BaseRedis|array|false
	{
		return $this->profile('xPending', [$key],
			fn() => parent::xPending($key, $group, $start, $end, $count, $consumer),
		);
	}
	
	// ----- misc -----
	
	public function rawCommand(
		string $command,
		mixed ...$args,
	): mixed
	{
		return $this->profile('rawCommand ' . $command, [],
			fn() => parent::rawCommand($command, ...$args),
		);
	}
	
	public function ping(
		?string $message = null,
	): BaseRedis|string|bool
	{
		return $this->profile('ping', [],
			fn() => parent::ping($message),
		);
	}
	
	public function info(
		string ...$sections,
	): BaseRedis|array|false
	{
		return $this->profile('info', [],
			fn() => parent::info(...$sections),
		);
	}
	
	// the cache backend routes versioned operations through redis functions —
	// without these overrides they would be invisible in the profiler
	public function fcall(
		string $fn,
		array $keys = [],
		array $args = [],
	): mixed
	{
		return $this->profile('fcall ' . $fn, $keys,
			fn() => parent::fcall($fn, $keys, $args),
		);
	}
	
	public function fcall_ro(
		string $fn,
		array $keys = [],
		array $args = [],
	): mixed
	{
		return $this->profile('fcall_ro ' . $fn, $keys,
			fn() => parent::fcall_ro($fn, $keys, $args),
		);
	}
	
	// ----- transactions (delegated, not profiled — see profile()) -----
	
	public function multi(
		int $value = BaseRedis::MULTI,
	): bool|BaseRedis
	{
		return parent::multi($value);
	}
	
	public function exec(): array|false
	{
		return parent::exec();
	}
}
