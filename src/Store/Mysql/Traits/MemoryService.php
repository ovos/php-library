<?php
declare(strict_types=1);

namespace Ovos\Store\Mysql\Traits;

use Ovos\Service\Memory;
use Ovos\Services;

/**
 * MemoryService
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
trait MemoryService
{
	/**
	 * @var Memory
	 */
	protected Memory $_memoryService;
	
	/**
	 * @return void
	 */
	public function initMemoryService(): void
	{
		/** @var Memory $memoryService */
		$memoryService = $this->_app->getServices()->get(Memory::SYMBOL);
		$this->_memoryService = $memoryService;
	}
	
	/**
	 * @param ?string $cacheKey
	 *
	 * @return bool
	 */
	public function invalidateMemory(?string $cacheKey = null): bool
	{
		$store = $this->_memoryService->getStore();
		$cacheId = self::TABLE;
		if($cacheKey !== null)
		{
			$cacheId .= '_' . $cacheKey;
		}
		
		return $store->delete($cacheId);
	}
}
