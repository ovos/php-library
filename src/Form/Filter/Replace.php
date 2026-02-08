<?php
declare(strict_types=1);

namespace Ovos\Form\Filter;

use Ovos\Form\Filter;

use function preg_replace;

/**
 * Replace
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Replace extends Filter
{
	protected string $pattern;
	
	protected ?string $replacement = null;
	
	public function __construct(
		string $pattern,
		?string $replacement = null,
	)
	{
		$this->setPattern($pattern);
		$this->setReplacement($replacement);
	}
	
	public function setPattern(
		string $pattern,
	): static
	{
		$this->pattern = $pattern;
		
		return $this;
	}
	
	public function getPattern(): string
	{
		return $this->pattern;
	}
	
	public function setReplacement(
		?string $replacement,
	): static
	{
		$this->replacement = $replacement;
		
		return $this;
	}
	
	public function getReplacement(): ?string
	{
		return $this->replacement;
	}
	
	public function filter(
		mixed $value,
	): ?string
	{
		if($value === null)
		{
			return null;
		}
		
		return preg_replace(
			$this->pattern,
			$this->replacement,
			$value,
		);
	}
}
