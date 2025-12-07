<?php
declare(strict_types=1);

namespace Ovos\Form\Filter;

use Ovos\Form\Filter;

use function str_starts_with;
use function strlen;
use function substr;

/**
 * StripPrefix
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class StripPrefix extends Filter
{
	protected ?string $prefix;
	
	public function __construct(
		?string $prefix,
	)
	{
		$this->setPrefix($prefix);
	}
	
	public function setPrefix(
		?string $prefix,
	): static
	{
		$this->prefix = $prefix;
		
		return $this;
	}
	
	public function getPrefix(): ?string
	{
		return $this->prefix;
	}
	
	public function filter(
		mixed $value,
	): ?string
	{
		if($value === null)
		{
			return null;
		}
		
		if($this->prefix === null)
		{
			return $value;
		}
		
		if(str_starts_with($value, $this->prefix) === false)
		{
			return $value;
		}
		
		return substr($value, strlen($this->prefix));
	}
}
