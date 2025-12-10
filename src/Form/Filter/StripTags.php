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
	protected array $allowedTags = [];
	
	public function __construct(
		array $allowedTags = [],
	)
	{
		$this->setAllowedTags($allowedTags);
	}
	
	public function setAllowedTags(
		array $allowedTags,
	): static
	{
		$this->allowedTags = $allowedTags;
		
		return $this;
	}
	
	public function filter(
		mixed $value,
	): ?string
	{
		if($value === null)
		{
			return null;
		}
		
		return strip_tags($value, $this->allowedTags);
	}
}
