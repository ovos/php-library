<?php
declare(strict_types=1);

namespace Ovos\Form\Filter;

use Ovos\Form\Filter;

use function str_starts_with;

/**
 * Prefix
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Prefix extends Filter
{
	protected string $prefix;
	
	public function __construct(
		string $prefix,
	)
	{
		$this->setPrefix($prefix);
	}
	
	public function setPrefix(
		string $prefix,
	): static
	{
		$this->prefix = $prefix;
		
		return $this;
	}
	
	public function getPrefix(): string
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
		
		if(str_starts_with($value, $this->prefix) === false)
		{
			return $value;
		}
		
		return $this->prefix . $value;
	}
}
