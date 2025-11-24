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
	/**
	 * @var string
	 */
	protected string $_defaultTitle;
	
	/**
	 * @var ?string
	 */
	protected ?string $_title = null;
	
	/**
	 * @var string
	 */
	protected string $_separator = ' &raquo; ';
	
	/**
	 * @var array
	 */
	protected array $_items = [];
	
	/**
	 * @param ?string $defaultTitle
	 *
	 * @return static
	 */
	public function title(?string $defaultTitle = null): static
	{
		if($defaultTitle !== null)
		{
			$this->setDefault($defaultTitle);
		}
		
		return $this;
	}
	
	/**
	 * @param string $title
	 *
	 * @return static
	 */
	public function set(string $title): static
	{
		$this->_title = $title;
		
		return $this;
	}
	
	/**
	 * @return string
	 */
	public function get(): string
	{
		return $this->_title;
	}
	
	/**
	 * @param string $defaultTitle
	 *
	 * @return static
	 */
	public function setDefault(string $defaultTitle): static
	{
		$this->_defaultTitle = $defaultTitle;
		
		return $this;
	}
	
	/**
	 * @return string
	 */
	public function getDefault(): string
	{
		return $this->_defaultTitle;
	}
	
	/**
	 * @param string $separator
	 *
	 * @return static
	 */
	public function setSeparator(string $separator): static
	{
		$this->_separator = $separator;
		
		return $this;
	}
	
	/**
	 * @param string $item
	 *
	 * @return static
	 */
	public function add(string $item): static
	{
		$this->_items[] = $item;
		
		return $this;
	}
	
	/**
	 * @return string
	 */
	public function __toString(): string
	{
		$items = $this->_items;
		array_unshift($items,
			$this->_title ?: $this->_defaultTitle);
		
		return implode($this->_separator, array_reverse($items));
	}
	
	/**
	 * @return array
	 */
	public function toArray(): array
	{
		return $this->_items;
	}
}
