<?php
declare(strict_types=1);

namespace Ovos\Form\Filter;

use Ovos\Form\Filter;

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
	protected $_pattern;

	/**
	 * @var string
	 */
	protected $_replacement;

	/**
	 * @param string $pattern
	 *
	 * @return $this
	 */
	public function setPattern(string $pattern): self
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
	 * @param string $replacement
	 *
	 * @return $this
	 */
	public function setReplacement(?string $replacement): self
	{
		$this->_replacement = $replacement;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getReplacement(): ?string
	{
		return $this->_replacement;
	}

	/**
	 * @param string $pattern
	 * @param string $replacement
	 */
	public function __construct(string $pattern, ?string $replacement = null)
	{
		$this->setPattern($pattern);
		$this->setReplacement($replacement);
	}

	/**
	 * @param null|mixed $value
	 *
	 * @return mixed
	 */
	public function filter($value): mixed
	{
		return preg_replace($this->_pattern, $this->_replacement, $value);
	}
}
