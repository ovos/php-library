<?php
declare(strict_types=1);

namespace Ovos\Store\Mysql\Traits;

use Ovos\Service\Memory;

/**
 * MemoryService
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
trait MemoryService
{
	protected Memory $memoryService;
	
	public function initMemoryService(): void
	{
		/** @var Memory $memoryService */
		$memoryService = $this->container->get(Memory::SYMBOL);
		$this->memoryService = $memoryService;
	}
	
	public function invalidateMemory(
		?string $cacheKey = null,
	): bool
	{
		$store = $this->memoryService
			->getStore();
		$cacheId = self::TABLE;
		if($cacheKey !== null)
		{
			$cacheId .= '_' . $cacheKey;
		}
		
		return $store->delete($cacheId);
	}
}
