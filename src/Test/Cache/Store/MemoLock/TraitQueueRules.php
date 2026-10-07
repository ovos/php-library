<?php
declare(strict_types=1);

namespace Ovos\Test\Cache\Store\MemoLock;

use Ovos\Cache\Store\KeyValue;
use Ovos\Test\Parallel;

use function count;
use function is_array;
use function json_decode;
use function sprintf;

/**
 * TraitQueueRules
 *
 * The parallel MemoLock rules the Redis store tests share, each run by
 * CLIENTS processes of the client script (see QueueClient): a lock-only
 * critical section, and the manual lock pattern
 *
 * @author Marcin Gil <mg@ovos.at>
 */
trait TraitQueueRules
{
	/**
	 * The client script the processes run (a QueueClient)
	 */
	abstract protected function clientScript(): string;
	
	/**
	 * RULE: a lock-only lockAndQueue() through the store is a mutex - a holder
	 * and two waiters run their critical sections one after another, never
	 * two at once (a waiter used to return on the holder's release WITHOUT
	 * taking the lock, so every waiter ran at the same time)
	 */
	public function aLockOnlySectionRunsOneAtATime(): bool
	{
		return $this->sectionRunsOneAtATime(QueueClient::MODE_SECTION);
	}
	
	/**
	 * RULE: the same through MemoLock alone, with no fetcher
	 */
	public function aStandaloneLockOnlySectionRunsOneAtATime(): bool
	{
		return $this->sectionRunsOneAtATime(QueueClient::MODE_STANDALONE);
	}
	
	/**
	 * RULE: the manual lock pattern hands a waiter the holder's value -
	 * lockAndQueue() returns what the holder wrote, or null to the process
	 * that holds the lock - so the work runs once and every process ends
	 * with the same value (a waiter used to get true, and the README's
	 * pattern computed again)
	 */
	public function theManualPatternHandsWaitersTheValue(): bool
	{
		$client = $this->store->getClient();
		
		try
		{
			$this->runClients(QueueClient::MODE_MANUAL);
			
			$values = [];
			foreach((array)$client->lRange($this->queueKey(QueueClient::KEY_ITEM_RESULTS), 0, -1) as $result)
			{
				$values[] = json_decode((string)$result, true);
			}
			
			$same = count($values) === self::CLIENTS
				&& is_array($values[0]);
			foreach($values as $value)
			{
				$same = $same && $value === $values[0];
			}
			
			return $same
				&& (int)$client->get($this->queueKey(QueueClient::KEY_ITEM_COUNTER)) === 1;
		}
		finally
		{
			$this->store->delete(QueueClient::KEY_ITEM);
			$this->deleteQueueKeys();
		}
	}
	
	/**
	 * Every client ran its critical section, and none found another inside
	 */
	protected function sectionRunsOneAtATime(
		string $mode,
	): bool
	{
		$client = $this->store->getClient();
		
		try
		{
			$this->runClients($mode);
			
			return (int)$client->get($this->queueKey(QueueClient::KEY_SECTION_RUNS)) === self::CLIENTS
				&& (int)$client->get($this->queueKey(QueueClient::KEY_SECTION_OVERLAPS)) === 0;
		}
		finally
		{
			$this->deleteQueueKeys();
		}
	}
	
	/**
	 * Runs CLIENTS processes of the client script in the given mode
	 * (QueueClient::MODE_*) and waits for all of them
	 */
	protected function runClients(
		string $mode,
	): void
	{
		$phpBinary = $this->config->getPath(['cli', 'executable']) ?? 'php';
		
		Parallel::run(sprintf('%s %s %s %s',
			$phpBinary,
			$this->clientScript(),
			KeyValue::GROUP_TESTS,
			$mode,
		), self::CLIENTS);
	}
	
	/**
	 * The store's id of a raw key the processes share
	 */
	protected function queueKey(
		string $name,
	): string
	{
		return $this->store->prefix($name, $this->store->getType());
	}
	
	/**
	 * Removes the raw keys the processes count in - one by one: on a
	 * cluster they live in different slots
	 */
	protected function deleteQueueKeys(): void
	{
		foreach([
			QueueClient::KEY_ITEM_COUNTER,
			QueueClient::KEY_ITEM_RESULTS,
			QueueClient::KEY_SECTION_INSIDE,
			QueueClient::KEY_SECTION_OVERLAPS,
			QueueClient::KEY_SECTION_RUNS,
		] as $name)
		{
			$this->store->getClient()
				->del($this->queueKey($name));
		}
	}
}
