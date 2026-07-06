<?php
declare(strict_types=1);

namespace Ovos\Session\Store;

use Ovos\ArrayObject;
use Ovos\Session\Store;
use ArrayObject as BaseArrayObject;

use function array_key_exists;
use function array_pop;
use function array_shift;
use function array_slice;
use function count;
use function is_array;
use function is_numeric;
use function session_write_close;

/**
 * Native
 *
 * The classic session storage: an array bound by reference to
 * $_SESSION (the native machinery reads it at session_start and writes
 * it back at the end, guarded by a session-wide lock) - or, on the CLI,
 * a plain local array that is never persisted. The path API walks the
 * array and its ArrayObject namespaces in place.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Native extends Store
{
	protected array $session = [];
	
	/**
	 * Whether the array is $_SESSION-bound - only then does close()
	 * hand the data back to the native machinery
	 */
	protected bool $bound = false;
	
	/**
	 * Binds the store to the given array BY REFERENCE ($_SESSION after
	 * a session_start); without one it works on a local, non-persisted
	 * array - the CLI mode
	 */
	public function __construct(
		?array &$session = null,
	)
	{
		if($session !== null)
		{
			$this->session = &$session;
			$this->bound = true;
		}
	}
	
	public function get(
		array $path,
	): mixed
	{
		$current = $this->session;
		foreach($path as $segment)
		{
			if($current instanceof BaseArrayObject)
			{
				if($current->offsetExists($segment) === false)
				{
					return null;
				}
				$current = $current->offsetGet($segment);
			}
			elseif(is_array($current) === true)
			{
				if(array_key_exists($segment, $current) === false)
				{
					return null;
				}
				$current = $current[$segment];
			}
			else
			{
				return null;
			}
		}
		
		return $current;
	}
	
	public function set(
		array $path,
		mixed $value,
	): void
	{
		if($path === [])
		{
			// assigns through the bound reference
			$this->session = is_array($value) === true
				? $value
				: (array)$value;
				
			return;
		}
		
		$last = array_pop($path);
		
		if($path === [])
		{
			$this->session[$last] = $value;
			
			return;
		}
		
		// missing (or scalar) parents become ArrayObjects, existing plain
		// arrays are wrapped - the framework's namespace convention
		$first = array_shift($path);
		$container = $this->session[$first] ?? null;
		if($container instanceof BaseArrayObject === false)
		{
			$container = new ArrayObject(
				is_array($container) === true ? $container : []);
			$this->session[$first] = $container;
		}
		
		foreach($path as $segment)
		{
			$next = $container->offsetExists($segment)
				? $container->offsetGet($segment)
				: null;
			if($next instanceof BaseArrayObject === false)
			{
				$next = new ArrayObject(
					is_array($next) === true ? $next : []);
				$container->offsetSet($segment, $next);
			}
			$container = $next;
		}
		
		$container->offsetSet($last, $value);
	}
	
	public function has(
		array $path,
	): bool
	{
		if($path === [])
		{
			return true;
		}
		
		$last = array_pop($path);
		$container = $this->get($path);
		
		if($container instanceof BaseArrayObject)
		{
			return $container->offsetExists($last);
		}
		if(is_array($container) === true)
		{
			return array_key_exists($last, $container);
		}
		
		return false;
	}
	
	public function remove(
		array $path,
	): void
	{
		if($path === [])
		{
			$this->session = [];
			
			return;
		}
		
		$last = array_pop($path);
		
		if($path === [])
		{
			unset($this->session[$last]);
			
			return;
		}
		
		$container = $this->get($path);
		
		if($container instanceof BaseArrayObject)
		{
			if($container->offsetExists($last))
			{
				$container->offsetUnset($last);
			}
			
			return;
		}
		
		if(is_array($container) === true)
		{
			// arrays are copies on the walk - reattach through the parent
			unset($container[$last]);
			$this->set($path, $container);
		}
	}
	
	/**
	 * A plain read-modify-write: the native machinery's session-wide
	 * lock already serializes parallel requests
	 */
	public function increment(
		array $path,
		int|float $by = 1,
	): int|float|null
	{
		// json-handler parity: a non-numeric value resets to 0 instead
		// of throwing a TypeError on the addition
		$current = $this->get($path);
		$value = (is_numeric($current) === true ? $current + 0 : 0) + $by;
		$this->set($path, $value);
		
		return $value;
	}
	
	public function append(
		array $path,
		mixed $value,
		int $limit = 0,
	): ?int
	{
		$list = $this->get($path);
		if($list instanceof BaseArrayObject)
		{
			$list = $list->getArrayCopy();
		}
		if(is_array($list) === false)
		{
			$list = [];
		}
		
		$list[] = $value;
		if($limit > 0 && count($list) > $limit)
		{
			$list = array_slice($list, -$limit);
		}
		$this->set($path, $list);
		
		return count($list);
	}
	
	public function getJourney(): array
	{
		$journey = $this->get([self::KEY_JOURNEY]);
		if($journey instanceof BaseArrayObject)
		{
			return $journey->getArrayCopy();
		}
		
		return is_array($journey) ? $journey : [];
	}
	
	protected function appendJourney(
		array $entry,
	): void
	{
		$journey = $this->getJourney();
		$journey[] = $entry;
		
		$this->session[self::KEY_JOURNEY] = $journey;
	}
	
	/**
	 * The historic by-reference accessor slot - auto-vivifies a missing
	 * key with null, so a namespace can be created through the reference
	 */
	public function &slot(
		string $name,
	): mixed
	{
		if(array_key_exists($name, $this->session) === false)
		{
			$this->session[$name] = null;
		}
		
		return $this->session[$name];
	}
	
	/**
	 * Hands the session back to the native machinery - only when bound
	 * to $_SESSION (the CLI's local array has nowhere to go)
	 */
	public function close(): void
	{
		if($this->bound === true)
		{
			session_write_close();
		}
	}
}
