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
	protected $_items = [];

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
	 * @return array
	 */
	public function toArray(): array
	{
		return $this->_items;
	}

	/**
	 * @return $this
	 */
	public function clear(): self
	{
		$this->_items = [];

		return $this;
	}
}