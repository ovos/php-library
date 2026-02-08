<?php
declare(strict_types=1);

namespace Ovos\Cache;

/**
 * Prefixer
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Prefixer
{
	// Separators
	public const string SEPARATOR_PREFIX = ':';
	
	protected ?string $prefix = null;
	
	public function __construct(
		?string $prefix = null,
	)
	{
		$this->prefix = $prefix;
	}
	
	public function prefix(
		string $key,
		?string $prefix = null,
		string $separator = self::SEPARATOR_PREFIX,
	): string
	{
		$prefix = $prefix ?? $this->prefix;
		
		return $prefix !== null
			? $prefix . $separator . $key
			: $key;
	}
	
	public function getPrefix(): ?string
	{
		return $this->prefix;
	}
}
