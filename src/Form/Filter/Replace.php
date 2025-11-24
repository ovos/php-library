<?php
declare(strict_types=1);

namespace Ovos\Form\Filter;

use Ovos\Form\Filter;

use function preg_replace;

/**
 * Replace
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Replace extends Filter
{
	/**
	 * @var string
	 */
	protected string $_pattern;
	
	/**
	 * @var ?string
	 */
	protected ?string $_replacement = null;
	
	/**
	 * @param string $pattern
	 *
	 * @return static
	 */
	public function setPattern(string $pattern): static
	{
		$this->_pattern = $pattern;
		
		return $this;
	}
	
	/**
	 * @return string
	 */
	public function getPattern(): string
	{
		return $this->_pattern;
	}
	
	/**
	 * @param ?string $replacement
	 *
	 * @return static
	 */
	public function setReplacement(?string $replacement): static
	{
		$this->_replacement = $replacement;
		
		return $this;
	}
	
	/**
	 * @return ?string
	 */
	public function getReplacement(): ?string
	{
		return $this->_replacement;
	}
	
	/**
	 * @param string $pattern
	 * @param ?string $replacement
	 */
	public function __construct(string $pattern, ?string $replacement = null)
	{
		$this->setPattern($pattern);
		$this->setReplacement($replacement);
	}
	
	/**
	 * @param mixed $value
	 *
	 * @return ?string
	 */
	public function filter(mixed $value): ?string
	{
		if($value === null)
		{
			return null;
		}
		
		return preg_replace($this->_pattern, $this->_replacement, $value);
	}
}
