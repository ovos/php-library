<?php
declare(strict_types=1);

namespace Ovos\Cache\Store;

use Ovos\Cache\MemoLock\Apcu as MemoLock;
use APCUIterator;
use Closure;

use function apcu_cache_info;
use function apcu_clear_cache;
use function apcu_delete;
use function apcu_fetch;
use function apcu_store;
use function is_string;

/**
 * Apcu
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Apcu extends KeyValue
{
	public function getMemoLock(): MemoLock
	{
		if($this->memoLock === null)
		{
			$this->memoLock = new MemoLock(
				$this->prefixer->getPrefix(),
				$this->config,
				$this,
			);
		}
		
		return $this->memoLock;
	}
	
	public function set(
		string $key,
		mixed $value,
		int $ttl = 0,
	): bool
	{
		$id = $this->prefixer
			->prefix($key, $this->getGroup());
		$value = $this->serializer
			->serialize($value);
		$value = $this->compressor
			->compress($value);
		
		try
		{
			return apcu_store($id, $value, $ttl);
		}
		finally
		{
			$this->getMemoLock()
				->releaseActiveLock($id);
		}
	}
	
	protected function fetch(
		string $id,
	): mixed
	{
		$value = apcu_fetch($id);
		
		if($value !== false)
		{
			$value = $this->compressor
				->decompress($value);
			return $this->serializer
				->unserialize($value);
		}
		
		return null;
	}
	
	public function get(
		string $key,
		?Closure $resolver = null,
		int $ttl = 0,
		?bool $queue = null, // override of the config switch
		?int $queueLockTtlS = null, // override of the config value
	): mixed
	{
		$id = $this->prefixer
			->prefix($key, $this->getGroup());
		
		// initial hit check (fast path)
		if(($data = $this->fetch($id)) !== null)
		{
			return $data;
		}
		
		return $this->getMemoLock()
			->lockAndQueue(
				$id,
				fn() => $this->fetch($id),
				fn() => $this->setFromResolver($key, $resolver, $ttl),
				$queue,
				$queueLockTtlS,
			);
	}
	
	public function lockAndQueue(
		string $key,
		?Closure $resolver = null,
		?int $queueLockTtlS = null, // override of the config value
	): mixed
	{
		$id = $this->prefixer
			->prefix($key, $this->getGroup());
		
		return $this->getMemoLock()
			->lockAndQueue(
				$id,
				null,
				$resolver,
				true,
				$queueLockTtlS,
			);
	}
	
	public function releaseActiveLock(
		string $key,
	): bool
	{
		$id = $this->prefixer
			->prefix($key, $this->getGroup());
		
		return $this->getMemoLock()
			->releaseActiveLock($id);
	}
	
	public function renewLock(
		string $key,
	): bool
	{
		$id = $this->prefixer
			->prefix($key, $this->getGroup());
		
		return $this->getMemoLock()
			->renewLock($id);
	}
	
	public function delete(
		string|APCUIterator $key,
	): bool
	{
		if(is_string($key))
		{
			$key = $this->prefixer
				->prefix($key, $this->getGroup());
		}
		
		return apcu_delete($key);
	}
	
	public function info(
		bool $limited = false,
	): bool|array
	{
		return apcu_cache_info($limited);
	}
	
	public function clear(): bool // always true
	{
		return apcu_clear_cache();
	}
}
