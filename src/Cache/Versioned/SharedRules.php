<?php
declare(strict_types=1);

namespace Ovos\Cache\Versioned;

use function apcu_add;
use function apcu_delete;
use function apcu_enabled;
use function apcu_exists;
use function apcu_fetch;
use function apcu_store;
use function function_exists;
use function hash;
use function is_array;
use function is_float;
use function is_int;

/**
 * SharedRules
 *
 * The rule set, kept in APCu so it survives the request.
 *
 * Under PHP-FPM every request is a fresh process and a fresh store instance,
 * and an instance that holds no rules has to load the whole stream - every
 * invalidation of the retention window - before its first read. Shared
 * through APCu, the compacted set (see Rules) is loaded once per server and
 * then only followed: a worker whose window has passed fetches the rules
 * appended since the set's last id and stores the result for the next one.
 *
 * The entry is keyed by the rules key and an identity of the connection, so
 * two environments that share one FPM pool and one prefix never read each
 * other's rules. Freshness is judged by the fetched-at stamp stored with the
 * set; the APCu TTL only reclaims entries of groups nobody reads any more.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class SharedRules
{
	/**
	 * How long an untouched entry stays in APCu. Freshness is decided by the
	 * stamp inside, not by this.
	 * Unit: seconds
	 */
	public const int TTL_S = 86400;
	
	/**
	 * How long the refresh flag may outlive a leader that never released it -
	 * a worker that died mid-refresh. APCu counts whole seconds, so two
	 * guarantees at least one.
	 * Unit: seconds
	 */
	public const int REFRESH_TTL_S = 2;
	
	protected const string SEPARATOR_IDENTITY = '@';
	protected const string SUFFIX_REFRESH = ':refresh';
	
	// Entry shape
	protected const string KEY_RULES = 'rules';
	protected const string KEY_FETCHED_AT_MS = 'fetched_at_ms';
	
	protected string $key;
	
	/**
	 * The election flag: whoever adds it refreshes the set, everyone else
	 * keeps what they hold until the refresh lands
	 */
	protected string $refreshKey;
	
	/**
	 * @param string $rulesKey the stream key, already carrying the prefix and the group
	 * @param string $identity what tells this connection from another one on the same server
	 */
	public function __construct(
		string $rulesKey,
		string $identity = '',
	)
	{
		$this->key = $rulesKey
			. static::SEPARATOR_IDENTITY
			. hash('xxh64', $identity);
		$this->refreshKey = $this->key . static::SUFFIX_REFRESH;
	}
	
	/**
	 * APCu is compiled in and switched on for this SAPI
	 */
	public static function isAvailable(): bool
	{
		return function_exists('apcu_enabled')
			&& apcu_enabled();
	}
	
	public function getKey(): string
	{
		return $this->key;
	}
	
	/**
	 * The stored set and the time it was fetched from the server, or null
	 * when there is none (or one we cannot read)
	 *
	 * @return array{Rules, float}|null
	 */
	public function load(
		int $retentionMs,
	): ?array
	{
		$entry = apcu_fetch($this->key, $success);
		
		if($success !== true
			|| is_array($entry) === false)
		{
			return null;
		}
		
		$fetchedAtMs = $entry[static::KEY_FETCHED_AT_MS] ?? null;
		$rules = Rules::fromArray($entry[static::KEY_RULES] ?? null, $retentionMs);
		
		if($rules === null
			|| (is_float($fetchedAtMs) === false && is_int($fetchedAtMs) === false))
		{
			return null;
		}
		
		return [$rules, (float)$fetchedAtMs];
	}
	
	/**
	 * Stores the set for the next worker - unless another worker stored a
	 * newer one meanwhile: two refreshes can race, and the slower one must not
	 * roll the entry back behind the rules the faster one already fetched
	 */
	public function store(
		Rules $rules,
		float $fetchedAtMs,
	): bool
	{
		$current = apcu_fetch($this->key, $success);
		$held = $success === true && is_array($current)
			? Rules::fromArray($current[static::KEY_RULES] ?? null, 0)
			: null;
		
		if($held !== null
			&& Rules::isNewerId($held->last(), $rules->last()))
		{
			return false;
		}
		
		return apcu_store($this->key, [
			static::KEY_RULES => $rules->toArray(),
			static::KEY_FETCHED_AT_MS => $fetchedAtMs,
		], static::TTL_S) === true;
	}
	
	public function forget(): bool
	{
		apcu_delete($this->refreshKey);
		
		return apcu_delete($this->key) === true;
	}
	
	/**
	 * Elects this worker to refresh the set. True when it should fetch, false
	 * when another worker holds the flag and is fetching right now.
	 *
	 * A flag APCu could not take - full, fragmented - elects nobody, so the
	 * caller refreshes on its own rather than trust a set nobody refreshes.
	 * Whoever was elected calls release() when the refresh has landed, or
	 * failed; a leader that dies leaves the flag to expire (REFRESH_TTL_S).
	 */
	public function lead(): bool
	{
		if(apcu_add($this->refreshKey, 1, static::REFRESH_TTL_S) === true)
		{
			return true;
		}
		
		return apcu_exists($this->refreshKey) === false;
	}
	
	public function release(): void
	{
		apcu_delete($this->refreshKey);
	}
	
	/**
	 * Is a worker elected and refreshing right now?
	 */
	public function isRefreshing(): bool
	{
		return apcu_exists($this->refreshKey) === true;
	}
}
