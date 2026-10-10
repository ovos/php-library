<?php
declare(strict_types=1);

namespace Ovos\Test\Cache\Store;

use Closure;
use Override;

use function array_column;

/**
 * TagIndexCounting
 *
 * For a probe of a tag-index store (Store\Redis, Redisearch - see
 * TraitTagIndexMiss): counts the locks taken with their look, the server
 * times read in a round trip of their own, and what each sendAll() sent
 * together
 *
 * @author Marcin Gil <mg@ovos.at>
 */
trait TagIndexCounting
{
	/** the locks taken with their look and the miss's stamp, in one round trip */
	public int $lookedWithLock = 0;
	
	/** run right before the lock with its look - a test lands a value meanwhile */
	public ?Closure $beforeLock = null;
	
	/** the server times read in a round trip of their own */
	public int $serverTimes = 0;
	
	/**
	 * The function names each sendAll() sent in one round trip
	 *
	 * @var list<list<string>>
	 */
	public array $sentTogether = [];
	
	/**
	 * What each sendAll() got back
	 *
	 * @var list<list<mixed>|null>
	 */
	public array $repliesTogether = [];
	
	#[Override]
	protected function lockAndLook(
		string $id,
	): ?Closure
	{
		if(($lockAndLook = parent::lockAndLook($id)) === null)
		{
			return null;
		}
		
		return function(string $lockKey, string $lockValue, int $ttlMs) use ($lockAndLook): array
		{
			if($this->beforeLock !== null)
			{
				($this->beforeLock)();
			}
			$this->lookedWithLock++;
			
			return $lockAndLook($lockKey, $lockValue, $ttlMs);
		};
	}
	
	#[Override]
	protected function serverTime(): ?string
	{
		$this->serverTimes++;
		
		return parent::serverTime();
	}
	
	#[Override]
	protected function sendAll(
		array $calls,
	): ?array
	{
		$this->sentTogether[] = array_column($calls, 0);
		$replies = parent::sendAll($calls);
		$this->repliesTogether[] = $replies;
		
		return $replies;
	}
}
