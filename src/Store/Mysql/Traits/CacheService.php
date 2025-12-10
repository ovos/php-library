<?php
declare(strict_types=1);

namespace Ovos\Store\Mysql\Traits;

use Ovos\Service\Cache;

/**
 * CacheService
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
trait CacheService
{
	protected Cache $cacheService;
	
	public function initCacheService(): void
	{
		/** @var Cache $cacheService */
		$cacheService = $this->container->get(Cache::SYMBOL);
		$this->cacheService = $cacheService;
	}
	
	public function invalidateCache(
		?string $cacheKey = null,
		bool $persistent = true,
	): bool
	{
		$store = $this->cacheService
			->getStore($persistent);
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
