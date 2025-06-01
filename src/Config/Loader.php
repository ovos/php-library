<?php
declare(strict_types=1);

namespace Ovos\Config;

use Ovos\Arrays;
use Ovos\ArrayObject;
use Ovos\Container\Inject;
use Ovos\Environment;
use Ovos\Service\Memory;

use function basename;
use function filemtime;
use function str_replace;

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
	 * @param Memory $memoryService
	 */
	public function __construct(
		#[Inject(Memory::SYMBOL)] Memory $memoryService,
	)
	{
		$this->_memoryService = $memoryService;
	}
	
	/**
	 * @param string $file
	 * @param Environment $environment
	 * @param ?string $cacheId
	 *
	 * @return ?ArrayObject
	 */
	public function load(string $file,
		Environment $environment,
		?string $cacheId = null
	): ?ArrayObject
	{
		$rootSection = $environment->getEnv();
		$mTime = filemtime($file);
		$cacheId = ($cacheId ?? basename($file)) . '_'
			. str_replace('-', '_', $rootSection);
		
		$store = $this->_memoryService->getStore();
		if(($item = $store->get($cacheId))
			&& $item->mtime === $mTime)
		{
			return $item->config;
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
		$configObject = Arrays::deepToArrayObject($config, ArrayObject::class);
		
		$item = new ArrayObject;
		$item->mtime = $mTime;
		$item->config = $configObject;
		$store->set($cacheId, $item);
		
		return $configObject;
	}
}
