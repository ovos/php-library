<?php
declare(strict_types=1);

namespace Ovos\Form\Filter;

use Ovos\Form\Filter;
use Ovos\Strings;

/**
 * Shorten
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Shorten extends Filter
{
	protected int $length;
	
	protected string $ending;
	
	public function __construct(
		int $length,
		string $ending = '...',
	)
	{
		$this->length = $length;
		$this->ending = $ending;
	}
	
	public function setLength(
		int $length,
	): static
	{
		$this->length = $length;
	
		return $this;
	}
	
	public function getLength(): int
	{
		return $this->length;
	}
	
	public function setEnding(
		string $ending,
	): static
	{
		$this->ending = $ending;
		
		return $this;
	}
	
	public function getEnding(): string
	{
		return $this->ending;
	}
	
	public function filter(
		mixed $value,
	): ?string
	{
		if($value === null)
		{
			return null;
		}
		
		return Strings::shorten($value,
			$this->getLength(),
			$this->getEnding(),
		);
	}
}
