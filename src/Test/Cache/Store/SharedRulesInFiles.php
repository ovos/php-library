<?php
declare(strict_types=1);

namespace Ovos\Test\Cache\Store;

use Closure;
use Override;

use function fclose;
use function file_get_contents;
use function file_put_contents;
use function flock;
use function fopen;
use function hash;
use function is_file;
use function is_resource;
use function serialize;
use function unlink;
use function unserialize;

use const DIRECTORY_SEPARATOR;
use const LOCK_EX;
use const LOCK_UN;

/**
 * SharedRulesInFiles
 *
 * The shared rule set kept in a directory instead of APCu, for tests that
 * need several processes to be one server: separate CLI processes each have
 * an APCu of their own. Every call works under an exclusive lock on one file
 * of the directory, so an add or a swap is one step for all of them, as it
 * is in APCu. $refuses makes the writes of the keys it names fail, as a full
 * APCu refuses them
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class SharedRulesInFiles extends SharedRulesProbe
{
	/**
	 * Called with a key before each write of it: true refuses the write
	 */
	public ?Closure $refuses = null;
	
	public function __construct(
		string $rulesKey,
		string $identity,
		protected string $directory,
	)
	{
		parent::__construct($rulesKey, $identity);
	}
	
	#[Override]
	protected function fetch(
		string $key,
	): mixed
	{
		return $this->locked(fn(): mixed => $this->read($key));
	}
	
	#[Override]
	protected function add(
		string $key,
		mixed $entry,
	): bool
	{
		return $this->locked(fn(): bool => $this->read($key) === null && $this->put($key, $entry));
	}
	
	#[Override]
	protected function write(
		string $key,
		mixed $entry,
	): bool
	{
		return $this->locked(fn(): bool => $this->put($key, $entry));
	}
	
	#[Override]
	protected function swap(
		string $key,
		int $old,
		int $new,
	): bool
	{
		return $this->locked(fn(): bool => $this->read($key) === $old && $this->put($key, $new));
	}
	
	#[Override]
	protected function exists(
		string $key,
	): bool
	{
		return $this->locked(fn(): bool => $this->read($key) !== null);
	}
	
	#[Override]
	protected function remove(
		string $key,
	): bool
	{
		return $this->locked(fn(): bool => is_file($this->path($key)) && unlink($this->path($key)));
	}
	
	protected function read(
		string $key,
	): mixed
	{
		$path = $this->path($key);
		if(is_file($path) === false)
		{
			return null;
		}
		
		$entry = unserialize((string)file_get_contents($path));
		
		return $entry === false
			? null
			: $entry;
	}
	
	protected function put(
		string $key,
		mixed $entry,
	): bool
	{
		if($this->refuses !== null
			&& ($this->refuses)($key) === true)
		{
			return false;
		}
		
		return file_put_contents($this->path($key), serialize($entry)) !== false;
	}
	
	protected function path(
		string $key,
	): string
	{
		return $this->directory . DIRECTORY_SEPARATOR . hash('xxh64', $key);
	}
	
	/**
	 * Runs $critical under the directory's lock
	 */
	protected function locked(
		Closure $critical,
	): mixed
	{
		$handle = fopen($this->directory . DIRECTORY_SEPARATOR . 'lock', 'c');
		if(is_resource($handle) === false)
		{
			return $critical();
		}
		
		try
		{
			flock($handle, LOCK_EX);
			
			return $critical();
		}
		finally
		{
			flock($handle, LOCK_UN);
			fclose($handle);
		}
	}
}
