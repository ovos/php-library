<?php
declare(strict_types=1);

namespace Ovos\Http;

use function array_is_list;
use function array_key_exists;
use function explode;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function json_decode;
use function preg_match;
use function trim;

/**
 * Input
 *
 * Typed access to decoded data. Missing or incompatible values use the supplied default.
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
	
	/**
	 * An Input reader over a raw JSON string — the response-side
	 * counterpart of Body::input() (which reads the request body).
	 * Malformed JSON or a non-array payload yields an empty reader,
	 * matching the tolerant contract above.
	 */
	public static function fromJson(
		?string $json,
		int $depth = 64,
	): self
	{
		$decoded = json_decode((string)$json, true, $depth);
		
		return new self(is_array($decoded) ? $decoded : []);
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
	
}
