<?php
declare(strict_types=1);

namespace Ovos\Config;

use Ovos\Arrays;
use Ovos\ArrayObject;
use Ovos\Cache\Key\Normalizer;
use Ovos\Container\Inject;
use Ovos\Environment;
use Ovos\Service\Memory;

use function basename;
use function filemtime;

/**
 * Loader
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Loader
{
	protected Memory $memoryService;
	
	public function __construct(
		#[Inject(Memory::SYMBOL)] Memory $memoryService,
	)
	{
		$this->memoryService = $memoryService;
	}
	
	public function load(
		string $file,
		Environment $environment,
		?string $cacheId = null,
	): ?ArrayObject
	{
		$store = $this->memoryService->getStore();
		
		$rootSection = $environment->getEnv();
		$mTime = filemtime($file);
		
		if($cacheId === null)
		{
			$fileId = Normalizer::fromPath(basename($file));
			$cacheId = $store->prefix($rootSection, $fileId);
		}
		
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
