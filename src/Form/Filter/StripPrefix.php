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
	/**
	 * @var ?string
	 */
	protected ?string $_prefix;
	
	/**
	 * @param ?string $prefix
	 *
	 * @return self
	 */
	public function setPrefix(?string $prefix): self
	{
		$this->_prefix = $prefix;
		
		return $this;
	}
	
	/**
	 * @return ?string
	 */
	public function getPrefix(): ?string
	{
		return $this->_prefix;
	}
	
	/**
	 * @param ?string $prefix
	 */
	public function __construct(?string $prefix)
	{
		$this->setPrefix($prefix);
	}
	
	/**
	 * @param mixed $value
	 *
	 * @return mixed
	 */
	public function filter(mixed $value): string
	{
		if($this->_prefix === null)
		{
			return $value;
		}
		
		if(str_starts_with($value, $this->_prefix) === false)
		{
			return $value;
		}
		
		return substr($value, strlen($this->_prefix));
	}
}
