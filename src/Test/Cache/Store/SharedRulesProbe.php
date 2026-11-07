<?php
declare(strict_types=1);

namespace Ovos\Test\Cache\Store;

use Closure;
use Ovos\Cache\Versioned\SharedRules;
use Override;

/**
 * SharedRulesProbe
 *
 * The shared rule set with what a test needs to stage a server: a lease
 * another worker took at a given moment, and a look at every write as it is
 * made
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class SharedRulesProbe extends SharedRules
{
	/**
	 * Called with the key and the entry before each write - an add, a swap or
	 * a store
	 */
	public ?Closure $beforeWrite = null;
	
	/**
	 * Holds the flag for another worker whose reload began at $atMs
	 */
	public function leaseSince(
		float $atMs,
	): void
	{
		$this->write($this->leaseKey, (int)$atMs * static::LEASE_NONCE + 1);
	}
	
	/**
	 * Puts the server in an outage whose last attempt ended at $endedAtMs,
	 * with no success recorded (see attemptEnded())
	 */
	public function outageSince(
		float $endedAtMs,
	): void
	{
		$this->write($this->attemptKey, [
			static::KEY_OUTAGE => true,
			static::KEY_ENDED_AT_MS => $endedAtMs,
			static::KEY_SUCCESS_AT_MS => null,
		]);
	}
	
	/**
	 * Whether $key is part of the mark of the newest invalidation (see
	 * written())
	 */
	public function isMark(
		string $key,
	): bool
	{
		return $key === $this->markAtUsKey
			|| $key === $this->markIdKey;
	}
	
	#[Override]
	protected function add(
		string $key,
		mixed $entry,
	): bool
	{
		$this->beforeWriting($key, $entry);
		
		return parent::add($key, $entry);
	}
	
	#[Override]
	protected function write(
		string $key,
		mixed $entry,
	): bool
	{
		$this->beforeWriting($key, $entry);
		
		return parent::write($key, $entry);
	}
	
	#[Override]
	protected function swap(
		string $key,
		int $old,
		int $new,
	): bool
	{
		$this->beforeWriting($key, $new);
		
		return parent::swap($key, $old, $new);
	}
	
	protected function beforeWriting(
		string $key,
		mixed $entry,
	): void
	{
		if($this->beforeWrite !== null)
		{
			($this->beforeWrite)($key, $entry);
		}
	}
}
