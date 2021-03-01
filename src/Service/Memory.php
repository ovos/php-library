<?php
declare(strict_types=1);

namespace Ovos\Service;

use Ovos\Service;
use Cache\Prefixed\PrefixedCachePool;
use Cache\Adapter\Apcu\ApcuCachePool;

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
	 * @var PrefixedCachePool
	 */
	protected null|PrefixedCachePool $_pool = null;
	
	/**
	 * @return string
	 */
	public function getSymbol(): string
	{
		return self::SYMBOL;
	}

	/**
	 * @return PrefixedCachePool
	 */
	public function getPool(): PrefixedCachePool
	{
		if($this->_pool === null)
		{
			$this->_pool = new PrefixedCachePool(
				new ApcuCachePool, str_replace([':', DIRECTORY_SEPARATOR], '', BASE_DIR) . '_');
		}

		return $this->_pool;
	}
}
