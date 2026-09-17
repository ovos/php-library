<?php
declare(strict_types=1);

namespace Tests\Cache\MemoLock;

use Ovos\Cache\MemoLock\Apcu as ApcuMemoLock;
use Ovos\Test;
use Ovos\Test\Internal;
use Override;

use function apcu_delete;
use function apcu_enabled;
use function apcu_fetch;
use function apcu_store;
use function function_exists;
use function microtime;

/**
 * A lock whose request has 150 ms left, whatever max_execution_time says
 */
class DeadlineApcuMemoLock extends ApcuMemoLock
{
	#[Override]
	public static function deadline(
		?int $limitS = null,
		?float $startedAt = null,
	): ?float
	{
		return microtime(true) + 0.15;
	}
}

/**
 * Apcu
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Apcu extends Test
{
	public const string KEY_ITEM = 'item';
	
	public function __construct()
	{
		if(function_exists('apcu_enabled') === false
			|| apcu_enabled() === false
		)
		{
			$this->setDisabled(true,
				'APCu is not enabled for this SAPI (apc.enable_cli).'
			);
		}
	}
	
	/**
	 * A waiter whose request is about to run out of time stops waiting and
	 * produces the value itself, well inside the 2 s the lock would wait
	 */
	public function waiterStopsAtTheDeadline(): bool
	{
		$memoLock = new DeadlineApcuMemoLock('tests');
		$lockKey = $memoLock
			->getPrefixer()
			->prefix(ApcuMemoLock::TYPE_LOCK, self::KEY_ITEM);
		
		try
		{
			// held by another process for longer than any wait here
			apcu_store($lockKey, 'foreign', 5);
			
			$start = microtime(true);
			$result = $memoLock
				->lockAndQueue(self::KEY_ITEM,
					fetcher: fn() => null,
					resolver: fn() => 'mine',
					queue: true,
				);
			$elapsed = microtime(true) - $start;
			
			// the deadline is 150 ms out: a nap or two, then our own value
			return $result === 'mine'
				&& $elapsed < 1.0
				&& apcu_fetch($lockKey) === 'foreign';
		}
		finally
		{
			apcu_delete($lockKey);
		}
	}
	
	/**
	 * Without a deadline the waiter sits out the whole wait timeout first
	 */
	public function waiterWithoutDeadlineWaitsItOut(): bool
	{
		$memoLock = new ApcuMemoLock('tests');
		$lockKey = $memoLock
			->getPrefixer()
			->prefix(ApcuMemoLock::TYPE_LOCK, self::KEY_ITEM);
		
		try
		{
			apcu_store($lockKey, 'foreign', 5);
			
			$start = microtime(true);
			$result = $memoLock
				->lockAndQueue(self::KEY_ITEM,
					fetcher: fn() => null,
					resolver: fn() => 'mine',
					queue: true,
				);
			$elapsed = microtime(true) - $start;
			
			// the CLI has no max_execution_time, so only the 2 s wait timeout applies
			return $result === 'mine'
				&& $elapsed >= 1.9;
		}
		finally
		{
			apcu_delete($lockKey);
		}
	}
	
	#[Internal]
	#[Override]
	public function finalize(): void
	{
	}
	
	#[Internal]
	#[Override]
	public function deconstruct(): void
	{
	}
}
