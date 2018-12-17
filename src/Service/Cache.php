<?php
declare(strict_types=1);

namespace Ovos\Service;

use Ovos\ArrayObject;
use Ovos\Service;
use Cache\Adapter\Redis\RedisCachePool;
use Cache\Adapter\Filesystem\FilesystemCachePool;
use function Ovos\services;

/**
 * Cache
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Cache extends Service
{
	/**
	 * @var string
	 */
	public const SYMBOL = 'cache';

	/**
	 * @var ArrayObject
	 */
	protected $_config;

	/**
	 * @var RedisCachePool
	 */
	protected $_pool;

	/**
	 * @var array
	 */
	protected $_dependsOn = ['events'];

	/**
	 */
	public function __construct()
	{
		parent::__construct();

		$this->_config = $this->_app->getConfig()->cache;
		$this->setEnabled($this->_config->enabled);
	}

	/**
	 * @return string
	 */
	public function getSymbol(): string
	{
		return self::SYMBOL;
	}

	/**
	 * @return RedisCachePool|null
	 */
	public function getPool(): ?RedisCachePool
	{
		if($this->_pool === null)
		{
			$client = new \Ovos\Cache\Redis;
			if($client->connect($this->_config) === false)
			{
				return null;
			}

			$this->_pool = $client->getCachePool();
		}

		return $this->_pool;
	}
}
