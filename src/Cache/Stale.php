<?php
declare(strict_types=1);

namespace Ovos\Cache;

use function max;
use function microtime;

use const INF;

/**
 * Stale
 *
 * A value written with a stale time (get(stale:)) or a time kept for errors
 * (get(staleIfError:)), as it is stored - every store carries it the same
 * way, inside the serialized value. Its windows are its own, on the
 * application's clock; the store's TTL (wrap() sets it to cover them) only
 * collects what is past them:
 * - fresh until $freshUntil (INF: a soft value written without a TTL, fresh
 *   until an invalidation reaches it);
 * - then servable for $staleFor seconds while one process refreshes it
 *   (stale-while-revalidate) - a read without stale: takes it for a miss;
 * - then kept for $errorFor seconds more: a miss, whose computation, if it
 *   throws, hands back this value instead of the exception.
 *
 * $staleFor is also the window after a soft invalidation: a soft value
 * (get(stale:, soft: true)) a tag invalidation reached is served that long
 * while it is recomputed. That verdict is the store's, made at read time
 * (invalidated()) and never stored. A hard value, and every delete() or
 * clear(), is a miss at once.
 *
 * wrap() decides which value is wrapped, and how - the one place for it
 *
 * @author Marcin Gil <mg@ovos.at>
 */
final readonly class Stale
{
	/**
	 * @param float $freshUntil when it stops being fresh (microtime; INF: never by age)
	 * @param int $staleFor seconds it may be served past its fresh time, or past a soft invalidation
	 * @param bool $soft a tag invalidation softens it to the stale time (see isSoft())
	 * @param int $errorFor seconds past the stale time it is kept for a computation that throws
	 * @param bool $invalidated a read found it softly invalidated - the store's verdict, never stored
	 */
	public function __construct(
		public mixed $value,
		public float $freshUntil,
		public int $staleFor = 0,
		public bool $soft = false,
		public int $errorFor = 0,
		public bool $invalidated = false,
	)
	{
	}
	
	/**
	 * What a value is stored as and for how long: with a TTL and a stale time
	 * or a time kept for errors, a record fresh for the TTL and stored for
	 * all three; a soft one also without a TTL (fresh until an invalidation,
	 * stored as long as before); anything else as it is. Soft needs a stale
	 * time, the time kept for errors a TTL
	 *
	 * @return array{0: mixed, 1: int} the value or its record, and its TTL
	 */
	public static function wrap(
		mixed $value,
		int $ttl,
		int $stale,
		bool $soft = false,
		int $staleIfError = 0,
	): array
	{
		$stale = max(0, $stale);
		$staleIfError = max(0, $staleIfError);
		$soft = $soft && $stale > 0;
		
		if($ttl > 0 && ($stale > 0 || $staleIfError > 0))
		{
			return [
				new self($value, microtime(true) + $ttl, $stale, $soft, $staleIfError),
				$ttl + $stale + $staleIfError,
			];
		}
		
		return $soft && $ttl <= 0
			? [new self($value, INF, $stale, true), $ttl]
			: [$value, $ttl];
	}
	
	public function isFresh(): bool
	{
		return $this->invalidated === false
			&& microtime(true) < $this->freshUntil;
	}
	
	/**
	 * Whether a value past its fresh time may still be served while it is
	 * refreshed: inside its stale time - or, softly invalidated, inside the
	 * window the store judged
	 */
	public function isServable(): bool
	{
		return $this->invalidated
			|| microtime(true) < $this->freshUntil + $this->staleFor;
	}
	
	/**
	 * Whether a value past its fresh time is kept for a computation that
	 * throws (get(staleIfError:)): until its stale time and the time kept for
	 * errors are over - softly invalidated, while the store keeps it
	 */
	public function isKeptForErrors(): bool
	{
		return $this->errorFor > 0
			&& $this->isFresh() === false
			&& ($this->invalidated
				|| microtime(true) < $this->freshUntil + $this->staleFor + $this->errorFor);
	}
	
	/**
	 * Whether a tag invalidation softens it: soft, and a stale time to serve
	 * it in
	 */
	public function isSoft(): bool
	{
		return $this->soft
			&& $this->staleFor > 0;
	}
	
	/**
	 * The same value, softly invalidated - how a read hands out a soft value
	 * an invalidation reached: served while it is refreshed, a miss to a read
	 * without stale:. Its fresh time stays; the mark is never stored
	 */
	public function invalidated(): self
	{
		return new self($this->value, $this->freshUntil, $this->staleFor, $this->soft, $this->errorFor, true);
	}
	
	/**
	 * What is stored - the invalidated mark is a read's verdict, not part of
	 * the value
	 */
	public function __serialize(): array
	{
		return [
			'value' => $this->value,
			'freshUntil' => $this->freshUntil,
			'staleFor' => $this->staleFor,
			'soft' => $this->soft,
			'errorFor' => $this->errorFor,
		];
	}
	
	/**
	 * A Stale written before the stale time and the soft flag were stored
	 * reads as a hard one; one written before staleIfError as kept for no
	 * error; a stored invalidated mark (written before it was left out) is
	 * ignored
	 */
	public function __unserialize(
		array $data,
	): void
	{
		$this->value = $data['value'] ?? null;
		$this->freshUntil = (float)($data['freshUntil'] ?? 0.0);
		$this->staleFor = (int)($data['staleFor'] ?? 0);
		$this->soft = (bool)($data['soft'] ?? false);
		$this->errorFor = (int)($data['errorFor'] ?? 0);
		$this->invalidated = false;
	}
}
