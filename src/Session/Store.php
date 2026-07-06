<?php
declare(strict_types=1);

namespace Ovos\Session;

use Closure;
use Throwable;

use function implode;
use function time;

/**
 * Store
 *
 * The storage surface behind Ovos\Service\Session: one store is picked
 * when the session starts - Native ($_SESSION or a plain array on the
 * CLI) or Json (the lazy RedisJSON handler) - and the Service delegates
 * every access to it without knowing which one it holds.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Store
{
	// The reserved journey timeline key within the session
	public const string KEY_JOURNEY = '__journey';
	
	// Journey entries
	public const string JOURNEY_REQUEST = 'request';
	public const string JOURNEY_ACTION = 'action';
	
	/**
	 * Reads the value at a nested path
	 */
	abstract public function get(
		array $path,
	): mixed;
	
	/**
	 * Reads several paths, values keyed by the dot-joined path
	 */
	public function getMany(
		array $paths,
	): array
	{
		$values = [];
		foreach($paths as $path)
		{
			$values[implode('.', $path)] = $this->get($path);
		}
		
		return $values;
	}
	
	/**
	 * Reads the value at a nested path and locks it for modification
	 * within this request; a store without value locks reads plainly
	 */
	public function getLocked(
		array $path,
	): mixed
	{
		return $this->get($path);
	}
	
	/**
	 * Locks the value, applies the updater and writes the result back,
	 * releasing the lock - the safe form of read-modify-write
	 */
	public function update(
		array $path,
		Closure $updater,
	): mixed
	{
		$value = $this->getLocked($path);
		
		try
		{
			$value = $updater($value);
		}
		catch(Throwable $throwable)
		{
			$this->releaseLock($path);
			
			throw $throwable;
		}
		
		$this->set($path, $value);
		
		return $value;
	}
	
	/**
	 * Writes the value at a nested path, creating missing parents
	 */
	abstract public function set(
		array $path,
		mixed $value,
	): void;
	
	abstract public function has(
		array $path,
	): bool;
	
	abstract public function remove(
		array $path,
	): void;
	
	/**
	 * Increments a numeric value at a nested path, creating it when
	 * missing; returns the new value
	 */
	abstract public function increment(
		array $path,
		int|float $by = 1,
	): int|float|null;
	
	/**
	 * Appends a value to a list at a nested path, creating it when
	 * necessary; with a limit the list keeps only its last "limit"
	 * entries; returns the resulting list length
	 */
	abstract public function append(
		array $path,
		mixed $value,
		int $limit = 0,
	): ?int;
	
	/**
	 * Releases a value lock taken by getLocked() without writing;
	 * a store without value locks has nothing to release
	 */
	public function releaseLock(
		array $path,
	): bool
	{
		return true;
	}
	
	/**
	 * Records a manual user action into the journey timeline
	 */
	public function addAction(
		string $action,
		array $data = [],
	): void
	{
		$this->appendJourney([
			't' => time(),
			'type' => self::JOURNEY_ACTION,
			'action' => $action,
		] + ($data !== [] ? ['data' => $data] : []));
	}
	
	/**
	 * Records a request into the journey timeline
	 */
	public function addRequest(
		string $method,
		string $url,
		array $data = [],
	): void
	{
		$this->appendJourney([
			't' => time(),
			'type' => self::JOURNEY_REQUEST,
			'method' => $method,
			'url' => $url,
		] + ($data !== [] ? ['data' => $data] : []));
	}
	
	/**
	 * The recorded requests and actions, oldest first
	 */
	abstract public function getJourney(): array;
	
	abstract protected function appendJourney(
		array $entry,
	): void;
	
	/**
	 * A by-reference slot for the magic accessors of the Service
	 */
	abstract public function &slot(
		string $name,
	): mixed;
	
	/**
	 * Ends the request's session work
	 */
	public function close(): void
	{
	}
}
