<?php
declare(strict_types=1);

namespace Ovos\View\Helper;

use Ovos\View\Helper\Placeholders\Placeholder;
use Ovos\View\Helper;

/**
 * Placeholders
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Placeholders extends Helper
{
	/**
	 * @var Placeholder[]
	 */
	protected array $items = [];
	
	public function __get(
		string $placeholder,
	): Placeholder
	{
		if(isset($this->items[$placeholder]) === false)
		{
			$this->items[$placeholder] = new Placeholder;
		}
		
		return $this->items[$placeholder];
	}
	
	public function __unset(
		string $placeholder,
	): void
	{
		if(isset($this->items[$placeholder]))
		{
			unset($this->items[$placeholder]);
		}
	}
	
	public function toArray(): array
	{
		return $this->items;
	}
	
	public function clear(): static
	{
		$this->items = [];
		
		return $this;
	}
}
