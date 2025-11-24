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
	protected array $_items = [];
	
	/**
	 * @param string $placeholder
	 *
	 * @return Placeholder
	 */
	public function __get(string $placeholder): Placeholder
	{
		if(!isset($this->_items[$placeholder]))
		{
			$this->_items[$placeholder] = new Placeholder;
		}
		
		return $this->_items[$placeholder];
	}
	
	/**
	 * @param string $placeholder
	 *
	 * @return void
	 */
	public function __unset(string $placeholder): void
	{
		if(isset($this->_items[$placeholder]))
		{
			unset($this->_items[$placeholder]);
		}
	}
	
	/**
	 * @return array
	 */
	public function toArray(): array
	{
		return $this->_items;
	}
	
	/**
	 * @return static
	 */
	public function clear(): static
	{
		$this->_items = [];
		
		return $this;
	}
}
