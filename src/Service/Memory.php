<?php
declare(strict_types=1);

namespace Ovos\Service;

use Ovos\Service;
use Ovos\Store\Apcu;

/**
 * Memory
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Memory extends Service
{
	/**
	 * @var string
	 */
	public const SYMBOL = 'memory';

	/**
	 * @var ?Apcu
	 */
	protected ?Apcu $_store = null;
	
	/**
	 * @return string
	 */
	public function getSymbol(): string
	{
		return self::SYMBOL;
	}

	/**
	 * @return Apcu
	 */
	public function getStore(): Apcu
	{
		if($this->_store === null)
		{
			$this->_store = new Apcu(str_replace([':', DIRECTORY_SEPARATOR], '', BASE_DIR) . '_');
		}

		return $this->_store;
	}
}
