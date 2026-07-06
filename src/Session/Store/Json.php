<?php
declare(strict_types=1);

namespace Ovos\Session\Store;

use Ovos\Session\Handler\RedisJson;
use Ovos\Session\Store;
use Closure;

/**
 * Json
 *
 * The lazy RedisJSON session storage behind the Store surface: every
 * access delegates to the RedisJson handler - per-path reads and
 * write-through writes, per-value locks, nothing loaded up front.
 * The magic accessor slots peek at the stored type: a scalar
 * materializes as its value, a container or a missing value stays a
 * lazy Node (see RedisJson::peek).
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Json extends Store
{
	protected RedisJson $handler;
	
	/**
	 * By-reference slots for the magic accessor returns
	 */
	protected array $peeked = [];
	
	public function __construct(
		RedisJson $handler,
	)
	{
		$this->handler = $handler;
	}
	
	public function handler(): RedisJson
	{
		return $this->handler;
	}
	
	public function get(
		array $path,
	): mixed
	{
		return $this->handler->get($path);
	}
	
	public function getMany(
		array $paths,
	): array
	{
		return $this->handler->getMany($paths);
	}
	
	public function getLocked(
		array $path,
	): mixed
	{
		return $this->handler->getLocked($path);
	}
	
	public function update(
		array $path,
		Closure $updater,
	): mixed
	{
		return $this->handler->update($path, $updater);
	}
	
	public function set(
		array $path,
		mixed $value,
	): void
	{
		$this->handler->set($path, $value);
	}
	
	public function has(
		array $path,
	): bool
	{
		return $this->handler->has($path);
	}
	
	public function remove(
		array $path,
	): void
	{
		$this->handler->remove($path);
	}
	
	public function increment(
		array $path,
		int|float $by = 1,
	): int|float|null
	{
		return $this->handler->increment($path, $by);
	}
	
	public function append(
		array $path,
		mixed $value,
		int $limit = 0,
	): ?int
	{
		return $this->handler->append($path, $value, $limit);
	}
	
	public function releaseLock(
		array $path,
	): bool
	{
		return $this->handler->releaseLock($path);
	}
	
	public function getJourney(): array
	{
		return $this->handler->getJourney();
	}
	
	protected function appendJourney(
		array $entry,
	): void
	{
		$this->handler->appendJourney($entry);
	}
	
	/**
	 * A fresh peek on every access; the slot only carries the
	 * by-reference return the magic accessors require
	 */
	public function &slot(
		string $name,
	): mixed
	{
		$this->peeked[$name] = $this->handler->peek([$name]);
		
		return $this->peeked[$name];
	}
	
	/**
	 * Releases every value lock still held by this request, waking all
	 * waiting parallel requests
	 */
	public function close(): void
	{
		$this->handler->close();
	}
}
