<?php
declare(strict_types=1);

namespace Ovos\Environment;

use Ovos\Environment;
use Ovos\Service\Memory;
use Ovos\Container\Inject;

use function basename;

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
	public function __construct(#[Inject(Memory::SYMBOL)] Memory $memoryService)
	{
		$this->_memoryService = $memoryService;
	}
	
	/**
	 * @param string $file
	 * @param ?string $cacheId
	 *
	 * @return ?Environment
	 */
	public function load(string $file,
		?string $cacheId = null
	): ?Environment
	{
		$cacheId = ($cacheId ?? basename($file));
		
		$store = $this->_memoryService->getStore();
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
