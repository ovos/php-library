<?php
declare(strict_types=1);

namespace Ovos\View\Helper;

use Ovos\View\Helper;

use function array_unshift;
use function array_reverse;
use function implode;

/**
 * Title
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Title extends Helper
{
	protected string $defaultTitle;
	
	protected ?string $title = null;
	
	protected string $separator = ' &raquo; ';
	
	protected array $items = [];
	
	public function title(
		?string $defaultTitle = null,
	): static
	{
		if($defaultTitle !== null)
		{
			$this->setDefault($defaultTitle);
		}
		
		return $this;
	}
	
	public function set(
		string $title,
	): static
	{
		$this->title = $title;
		
		return $this;
	}
	
	public function get(): string
	{
		return $this->title;
	}
	
	public function setDefault(
		string $defaultTitle,
	): static
	{
		$this->defaultTitle = $defaultTitle;
		
		return $this;
	}
	
	public function getDefault(): string
	{
		return $this->defaultTitle;
	}
	
	public function setSeparator(
		string $separator,
	): static
	{
		$this->separator = $separator;
		
		return $this;
	}
	
	public function add(
		string $item,
	): static
	{
		$this->items[] = $item;
		
		return $this;
	}
	
	public function __toString(): string
	{
		$items = $this->items;
		array_unshift($items,
			$this->title ?: $this->defaultTitle);
		
		return implode($this->separator, array_reverse($items));
	}
	
	public function toArray(): array
	{
		return $this->items;
	}
}
