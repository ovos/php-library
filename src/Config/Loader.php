<?php
declare(strict_types=1);

namespace Ovos\Config;

use Ovos\Arrays;
use Ovos\Cache\Filesystem;
use Ovos\ArrayObject;
use Ovos\Exception;
use Ovos\Service\Memory;
use Ovos\Services;
use function Ovos\services;

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
	protected $_memoryService;

	/**
	 * @var string
	 */
	public const CACHE_DIR = 'configs' . DIRECTORY_SEPARATOR;

	/**
	 */
	public function __construct()
	{
		$this->_memoryService = Services::getInstance()->get(Memory::SYMBOL);
	}

	/**
	 * @param string $file
	 * @param string $rootSection
	 * @param string $cacheId
	 *
	 * @return ArrayObject|null
	 */
	public function load(string $file,
		string $rootSection,
		string $cacheId = null
	): ?ArrayObject
	{
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
				return $itemValue->config;
			}
		}

		$config = Parser::parse($file);
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
