<?php
declare(strict_types=1);

namespace Ovos\Store\Mysql\Traits;

use Ovos\Service\Cache;
use Ovos\Services;

/**
 * CacheService
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
trait CacheService
{
	/**
	 * @var Cache
	 */
	protected Cache $_cacheService;
	
	/**
	 * @return void
	 */
	public function initCacheService(): void
	{
		/** @var Cache $cacheService */
		$cacheService = $this->_app->getServices()
			->get(Cache::SYMBOL);
		$this->_cacheService = $cacheService;
	}
	
	/**
	 * @param ?string $cacheKey
	 * @param bool $persistent
	 *
	 * @return bool
	 */
	public function invalidateCache(
		?string $cacheKey = null,
		bool $persistent = true,
	): bool
	{
		$store = $this->_cacheService->getStore($persistent);
		if($store === null)
		{
			return false;
		}
		
		$cacheId = self::TABLE;
		if($cacheKey !== null)
		{
			$cacheId .= '_' . $cacheKey;
		}
		
		return $store->delete($cacheId);
	}
}
