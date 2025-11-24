<?php
declare(strict_types=1);

namespace Ovos\Form\Filter;

use Ovos\Form\Filter;

use function strip_tags;

/**
 * StripTags
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class StripTags extends Filter
{
	/**
	 * @var array 
	 */
	protected array $_allowedTags = [];
	
	/**
	 * @param array $allowedTags
	 */
	public function __construct(array $allowedTags = [])
	{
		$this->setAllowedTags($allowedTags);
	}
	
	/**
	 * @param array $allowedTags
	 *
	 * @return static
	 */
	public function setAllowedTags(array $allowedTags): static
	{
		$this->_allowedTags = $allowedTags;
		
		return $this;
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
		
		return strip_tags($value, $this->_allowedTags);
	}
}
