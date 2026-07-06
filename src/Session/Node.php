<?php
declare(strict_types=1);

namespace Ovos\Session;

use Ovos\Session\Handler\RedisJson;
use ArrayAccess;
use ArrayIterator;
use Closure;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

use function count;
use function is_array;

/**
 * Node
 *
 * A lazy accessor for a nested path inside a JSON session document.
 * child() extends the path with NO I/O; the magic/ArrayAccess reads
 * ($node->basket->products, $node['basket']) peek at the stored type -
 * a scalar materializes as its value, a container or a missing value
 * stays a Node. The terminal calls - get(), getLocked(), set(),
 * remove(), exists(), increment(), append(), update(), iteration,
 * count() and the isset()/unset() magic - cost one round trip.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Node implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
	protected RedisJson $handler;
	
	protected array $path;
	
	public function __construct(
		RedisJson $handler,
		array $path = [],
	)
	{
		$this->handler = $handler;
		$this->path = $path;
	}
	
	/**
	 * The path segments this node points at
	 */
	public function path(): array
	{
		return $this->path;
	}
	
	/**
	 * A child node, one path segment deeper - no I/O
	 */
	public function child(
		string $name,
	): static
	{
		return new static($this->handler,
			[...$this->path, $name]);
	}
	
	/**
	 * Reads the value at this path (waits when locked by another request)
	 */
	public function get(): mixed
	{
		return $this->handler
			->get($this->path);
	}
	
	/**
	 * Reads the value at this path and locks it for modification within
	 * this request; readers in parallel requests wait until set()
	 */
	public function getLocked(): mixed
	{
		return $this->handler
			->getLocked($this->path);
	}
	
	/**
	 * Writes the value at this path and releases a held lock
	 */
	public function set(
		mixed $value,
	): static
	{
		$this->handler
			->set($this->path, $value);
		
		return $this;
	}
	
	public function exists(): bool
	{
		return $this->handler
			->has($this->path);
	}
	
	public function remove(): static
	{
		$this->handler
			->remove($this->path);
		
		return $this;
	}
	
	public function increment(
		int|float $by = 1,
	): int|float|null
	{
		return $this->handler
			->increment($this->path, $by);
	}
	
	/**
	 * Atomically appends a value to the list at this path - lock-free
	 */
	public function append(
		mixed $value,
		int $limit = 0,
	): ?int
	{
		return $this->handler
			->append($this->path, $value, $limit);
	}
	
	/**
	 * Locks the value, applies the updater and writes the result back
	 * (releasing the lock) - the safe form of read-modify-write
	 */
	public function update(
		Closure $updater,
	): mixed
	{
		return $this->handler
			->update($this->path, $updater);
	}
	
	/**
	 * Releases the lock taken by getLocked() without writing
	 */
	public function releaseLock(): bool
	{
		return $this->handler
			->releaseLock($this->path);
	}
	
	/**
	 * Magic read: a scalar materializes as its value, a container or a
	 * missing value stays a lazy Node (see RedisJson::peek); child()
	 * forces traversal without the peek
	 */
	public function __get(
		string $name,
	): mixed
	{
		return $this->handler
			->peek([...$this->path, $name]);
	}
	
	public function __set(
		string $name,
		mixed $value,
	): void
	{
		$this->child($name)
			->set($value);
	}
	
	public function __isset(
		string $name,
	): bool
	{
		return $this->child($name)
			->exists();
	}
	
	public function __unset(
		string $name,
	): void
	{
		$this->child($name)
			->remove();
	}
	
	/**
	 * ArrayAccess read, with the same peek semantics as the magic read
	 */
	public function offsetGet(
		mixed $offset,
	): mixed
	{
		return $this->handler
			->peek([...$this->path, (string)$offset]);
	}
	
	public function offsetSet(
		mixed $offset,
		mixed $value,
	): void
	{
		$this->child((string)$offset)
			->set($value);
	}
	
	public function offsetExists(
		mixed $offset,
	): bool
	{
		return $this->child((string)$offset)
			->exists();
	}
	
	public function offsetUnset(
		mixed $offset,
	): void
	{
		$this->child((string)$offset)
			->remove();
	}
	
	/**
	 * Iteration materializes the value at this path
	 */
	public function getIterator(): Traversable
	{
		$value = $this->get();
		
		return new ArrayIterator(
			is_array($value) === true
				? $value
				: []
		);
	}
	
	public function count(): int
	{
		$value = $this->get();
		
		return is_array($value) === true
			? count($value)
			: 0;
	}
	
	public function jsonSerialize(): mixed
	{
		return $this->get();
	}
}
