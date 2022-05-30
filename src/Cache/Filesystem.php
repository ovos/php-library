<?php
declare(strict_types=1);

namespace Ovos\Cache;

use Ovos\ArrayObject;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem as BaseFilesystem;
use Cache\Adapter\Filesystem\FilesystemCachePool;
use function Ovos\services;

/**
 * Cache
 * Deprecated, kept for future reference
 *
 * @deprecated
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Filesystem
{
	/**
	 * @var FilesystemCachePool
	 */
	protected $_pool;

	/**
	 * @return FilesystemCachePool
	 */
	public function getCachePool(): FilesystemCachePool
	{
		if($this->_pool === null)
		{
			$filesystemAdapter = new Local(BASE_DIR . 'application' . DIRECTORY_SEPARATOR . 'cache');
			$filesystem = new BaseFilesystem($filesystemAdapter);

			$this->_pool = new FilesystemCachePool($filesystem);
		}

		return $this->_pool;
	}
}
