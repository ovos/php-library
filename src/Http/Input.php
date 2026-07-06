<?php
declare(strict_types=1);

namespace Ovos\Http;

use function array_is_list;
use function array_key_exists;
use function array_slice;
use function explode;
use function filter_var;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function preg_match;
use function trim;

use const FILTER_VALIDATE_EMAIL;

/**
 * Input
 *
 * A typed, forgiving reader over a decoded JSON request body, so API
 * actions stop hand-writing the same defensive casts
 * (is_string($body['x'] ?? null) ? ... : '', (int)($body['page'] ?? 1),
 * ($body['flag'] ?? true) === true). Dot-paths reach nested values
 * ("sort.field"); every getter returns the typed value or the default,
 * never a warning.
 *
 * Missing/malformed bodies produce an EMPTY Input (isEmpty() === true),
 * so the tolerant "$body ?? []" style becomes just $this->input().
 *
 * @author Marcin Gil <mg@ovos.at>
 */
final class Input
{
	public function __construct(
		protected readonly array $data = [],
	)
	{
	}
	
	public function isEmpty(): bool
	{
		return $this->data === [];
	}
	
	/**
	 * The whole decoded body - for passing an untyped sub-structure
	 * straight to a service (e.g. a grid's filter map)
	 */
	public function all(): array
	{
		return $this->data;
	}
	
	/**
	 * Whether a (possibly nested) key exists at all, even if its value
	 * is null
	 */
	public function has(
		string $path,
	): bool
	{
		$node = $this->data;
		foreach(explode('.', $path) as $key)
		{
			if(is_array($node) === false
				|| array_key_exists($key, $node) === false)
			{
				return false;
			}
			$node = $node[$key];
		}
		
		return true;
	}
	
	/**
	 * The raw value at a dot-path, or the default when absent
	 */
	public function get(
		string $path,
		mixed $default = null,
	): mixed
	{
		$node = $this->data;
		foreach(explode('.', $path) as $key)
		{
			if(is_array($node) === false
				|| array_key_exists($key, $node) === false)
			{
				return $default;
			}
			$node = $node[$key];
		}
		
		return $node;
	}
	
	/**
	 * A scalar as a string; arrays/objects/null fall back to the default
	 */
	public function string(
		string $path,
		string $default = '',
	): string
	{
		$value = $this->get($path);
		
		if(is_string($value) === true)
		{
			return $value;
		}
		
		return is_int($value) || is_float($value) || is_bool($value)
			? (string)$value
			: $default;
	}
	
	/**
	 * A trimmed string - the common form for search terms, names, etc.
	 */
	public function trimmed(
		string $path,
		string $default = '',
	): string
	{
		$value = trim($this->string($path, $default));
		
		return $value === '' ? $default : $value;
	}
	
	/**
	 * A real int or a digit string; never a blind (int) cast (which
	 * coerces arrays and true to 1)
	 */
	public function int(
		string $path,
		int $default = 0,
	): int
	{
		$value = $this->get($path);
		
		if(is_int($value) === true)
		{
			return $value;
		}
		
		// only a clean integer literal (optionally signed) - never a blind
		// (int) cast, which turns "12abc" into 12 and arrays/true into 1
		if(is_string($value) === true
			&& preg_match('~^-?\d+$~', $value) === 1)
		{
			return (int)$value;
		}
		
		return $default;
	}
	
	public function float(
		string $path,
		float $default = 0.0,
	): float
	{
		$value = $this->get($path);
		
		return is_int($value) || is_float($value)
			|| (is_string($value) && is_numeric($value))
			? (float)$value
			: $default;
	}
	
	/**
	 * A boolean with the framework's string semantics (true/"true"/"yes"/
	 * 1 are true; false/"false"/"no"/0 are false); anything else defaults
	 */
	public function bool(
		string $path,
		bool $default = false,
	): bool
	{
		$value = $this->get($path);
		
		if(is_bool($value) === true)
		{
			return $value;
		}
		
		return match($value)
		{
			'true', 'yes', '1', 1 => true,
			'false', 'no', '0', 0 => false,
			default => $default,
		};
	}
	
	/**
	 * An array value (any shape), or the default
	 */
	public function array(
		string $path,
		array $default = [],
	): array
	{
		$value = $this->get($path);
		
		return is_array($value) === true ? $value : $default;
	}
	
	/**
	 * A plain list (sequential array), or the default - objects/maps do
	 * not qualify
	 */
	public function list(
		string $path,
		array $default = [],
	): array
	{
		$value = $this->get($path);
		
		return is_array($value) === true && array_is_list($value) === true
			? $value
			: $default;
	}
	
	/**
	 * The capped positive-int id selection: a single {id} or an {ids:[…]}
	 * list at the body root (delegates to Body::ids)
	 */
	public function ids(
		int $max = 100,
		string $singleKey = 'id',
		string $listKey = 'ids',
	): array
	{
		return Body::ids($this->data, $max, $singleKey, $listKey);
	}
	
	/**
	 * A single validated email, or null
	 */
	public function email(
		string $path,
	): ?string
	{
		$value = trim($this->string($path));
		
		return $value !== ''
			&& filter_var($value, FILTER_VALIDATE_EMAIL) !== false
			? $value
			: null;
	}
	
	/**
	 * A validated email list; returns null when ANY entry is invalid, so
	 * the caller can 422 the whole payload rather than silently drop one
	 *
	 * @return string[]|null
	 */
	public function emails(
		string $path,
		int $max = 100,
	): ?array
	{
		$emails = [];
		foreach(array_slice($this->list($path), 0, $max) as $value)
		{
			if(is_string($value) === false
				|| filter_var($value = trim($value), FILTER_VALIDATE_EMAIL) === false)
			{
				return null;
			}
			$emails[] = $value;
		}
		
		return $emails;
	}
}
