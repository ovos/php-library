<?php
declare(strict_types=1);

namespace Ovos\Environment;

use Ovos\Environment;
use Ovos\Arrays;
use Ovos\Exception;
use Ovos\Service\Memory;
use Ovos\Services;

/**
 * Loader
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Loader
{
	/**
	 * @var Memory
	 */
	protected Memory $_memoryService;
	
	/**
	 */
	public function __construct()
	{
		$this->_memoryService = Services::getInstance()->get(Memory::SYMBOL);
	}

	/**
	 * @param string $file
	 * @param string $cacheId
	 *
	 * @return null|Environment
	 */
	public function load(string $file,
		string $cacheId = null
	): ?Environment
	{
		$cacheId = ($cacheId ?? basename($file));
		
		$pool = $this->_memoryService->getPool();
		if($pool->hasItem($cacheId))
		{
			$item = $pool->getItem($cacheId);
			return $item->get();
		}

		$config = Parser::parse($file);
		if($config === null)
		{
			return null;
		}
		
		$env = new Environment($config);

		$item = $pool->getItem($cacheId);
		$item->set($env);
		$pool->save($item);

		return $env;
	}
}
