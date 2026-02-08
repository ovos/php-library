<?php
declare(strict_types=1);

namespace Ovos\Environment;

use Ovos\Environment;
use Ovos\Service\Memory;
use Ovos\Container\Inject;
use Ovos\Cache\Key;

use function basename;

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
		?string $cacheId = null
	): ?Environment
	{
		$store = $this->memoryService->getStore();
		
		$cacheId = ($cacheId ?? Key::fromPath(basename($file)));
		if(($value = $store->get($cacheId)))
		{
			return $value;
		}
		
		$config = Parser::parse($file);
		if($config === null)
		{
			return null;
		}
		
		$env = new Environment($config);
		$store->set($cacheId, $env);
		
		return $env;
	}
}
