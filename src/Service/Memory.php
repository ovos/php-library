<?php
declare(strict_types=1);

namespace Ovos\Service;

use Ovos\Service;
use Ovos\Store\Apcu;

use function str_replace;

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
	public const string SYMBOL = 'memory';
	
	/**
	 * @var ?Apcu
	 */
	protected ?Apcu $_store = null;
	
	/**
	 * @return Apcu
	 */
	public function getStore(): Apcu
	{
		if($this->_store === null)
		{
			$home = getenv('HOME', true);
			$baseDirHomeless = $home === false
				? BASE_DIR
				: substr(BASE_DIR, strlen($home));
			$this->_store = new Apcu(Apcu::pathToId($baseDirHomeless)); // path as prefix
		}
		
		return $this->_store;
	}
}
