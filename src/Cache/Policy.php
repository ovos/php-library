<?php
declare(strict_types=1);

namespace Ovos\Cache;

use InvalidArgumentException;
use TypeError;

use function array_key_exists;
use function count;
use function implode;
use function in_array;
use function is_int;
use function sprintf;

/**
 * Policy
 *
 * The options get() takes after $tags, read from its variadic
 * (mixed ...$options): by name as callers write them -
 * get($key, $fn, 300, ['tag'], stale: 60, soft: true) - or by position in
 * OPTIONS' order. An unknown name, a position too many, an option given
 * twice or of the wrong type throws: a misspelt option is never silently
 * ignored. Every store has the one get() signature, which an override copies
 * once and a new option never changes
 *
 * @author Marcin Gil <mg@ovos.at>
 */
final readonly class Policy
{
	/**
	 * The options, in the order a positional caller passes them
	 */
	public const array OPTIONS = ['queue', 'queueLockTtlMs', 'stale', 'soft', 'refresh', 'staleIfError'];
	
	/**
	 * @param ?bool $queue the MemoLock queue for this call (null: the config's)
	 * @param ?int $queueLockTtlMs the lock's TTL for this call, in milliseconds (null: the config's)
	 * @param int $stale seconds past the TTL a value is served while one process refreshes it
	 * @param bool $soft a tag invalidation softens the value to its stale time too
	 * @param bool $refresh recompute whatever is cached - guarded like a miss
	 * @param int $staleIfError seconds past the stale time a value is kept, the answer when its computation throws
	 */
	public function __construct(
		public ?bool $queue = null,
		public ?int $queueLockTtlMs = null,
		public int $stale = 0,
		public bool $soft = false,
		public bool $refresh = false,
		public int $staleIfError = 0,
	)
	{
	}
	
	/**
	 * get()'s ...$options as a Policy
	 *
	 * @throws InvalidArgumentException an unknown option, one position too many, one given twice
	 * @throws TypeError an option of the wrong type
	 */
	public static function from(
		array $options,
	): self
	{
		$named = [];
		foreach($options as $name => $value)
		{
			if(is_int($name))
			{
				if(isset(self::OPTIONS[$name]) === false)
				{
					throw new InvalidArgumentException(sprintf('get() takes %d options after $tags, given one at position %d',
						count(self::OPTIONS), $name + 1));
				}
				$name = self::OPTIONS[$name];
			}
			if(in_array($name, self::OPTIONS, true) === false)
			{
				throw new InvalidArgumentException(sprintf('Unknown cache option "%s" - one of: %s',
					$name, implode(', ', self::OPTIONS)));
			}
			if(array_key_exists($name, $named))
			{
				throw new InvalidArgumentException(sprintf('The cache option "%s" is given twice', $name));
			}
			$named[$name] = $value;
		}
		
		return new self(...$named);
	}
}
