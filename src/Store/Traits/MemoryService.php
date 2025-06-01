<?php
declare(strict_types=1);

namespace Ovos\Store\Traits;

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
	 * @param ?string $cache
	 *
	 * @return bool
	 */
	public function invalidateCache(?string $cache = null): bool
	{
		$store = $this->_memoryService->getStore();
		$cacheId = self::TABLE;
		if($cache !== null)
		{
			$cacheId .= '_' . $cache;
		}
		
		return $store->delete($cacheId);
	}
}
