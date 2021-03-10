<?php
declare(strict_types=1);

namespace Ovos\Config;

use Ovos\Arrays;
use Ovos\Cache\Filesystem;
use Ovos\ArrayObject;
use Ovos\Environment;
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
	 * @var string
	 */
	//public const CACHE_DIR = 'configs' . DIRECTORY_SEPARATOR;
	
	/**
	 */
	public function __construct()
	{
		$this->_memoryService = Services::getInstance()->get(Memory::SYMBOL);
	}

	/**
	 * @param string $file
	 * @param Environment $environment
	 * @param string $cacheId
	 *
	 * @return null|ArrayObject
	 */
	public function load(string $file,
		Environment $environment,
		string $cacheId = null
	): ?ArrayObject
	{
		$rootSection = $environment->getEnv();
		$mTime = filemtime($file);
		$cacheId = ($cacheId ?? basename($file)) . '_'
			. str_replace('-', '_', $rootSection);
		
		/*
		$filesystem = new Filesystem;
		$pool = $filesystem->getCachePool();
		$pool->setFolder(self::CACHE_DIR);
		*/
		
		$pool = $this->_memoryService->getPool();
		if($pool->hasItem($cacheId))
		{
			$item = $pool->getItem($cacheId);
			$itemValue = $item->get();
			if($itemValue->mtime === $mTime)
			{
				//return $itemValue->config;
			}
		}

		$config = Parser::parse($file, $environment);
		if($config === null)
		{
			return null;
		}

		if(!isset($config[$rootSection]))
		{
			return null;
		}

		$config = $config[$rootSection];
		$configObject = Arrays::deepToArrayObject($config, 'Ovos\ArrayObject');

		$cacheObject = new ArrayObject;
		$cacheObject->mtime = $mTime;
		$cacheObject->config = $configObject;

		$item = $pool->getItem($cacheId);
		$item->set($cacheObject);
		$pool->save($item);

		return $configObject;
	}
}
