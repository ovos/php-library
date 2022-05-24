<?php
declare(strict_types=1);

namespace Ovos\Form\Filter;

use Ovos\Form\Filter;

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
	 * @return self
	 */
	public function setAllowedTags(array $allowedTags): self
	{
		$this->_allowedTags = $allowedTags;
		
		return $this;
	}

	/**
	 * @param mixed $value
	 *
	 * @return mixed
	 */
	public function filter(mixed $value): string
	{
		return strip_tags($value, $this->_allowedTags);
	}
}
