<?php
declare(strict_types=1);

namespace Ovos\Environment;

use Ovos\Environment;
use Ovos\Service\Memory;
use Ovos\Container\Inject;
use Ovos\Cache\Key\Normalizer;

use function basename;

/**
 * Loader
 *
 * Reads a .env file into an Environment. The parsed object is NOT cached by
 * load() itself: the caller decides, via remember(), once the environment has
 * proven usable (its section exists in environments.yml). A .env naming an
 * unknown environment would otherwise be cached with no way to drop it - the
 * entry carries no mtime, and the cache clear needs a boot that works.
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
	
	/**
	 * Returns the remembered Environment for the file, or parses the file
	 * (without remembering it); null when the file does not parse.
	 */
	public function load(
		string $file,
		?string $cacheId = null,
	): ?Environment
	{
		$store = $this->memoryService->getStore();
		
		$cacheId ??= static::cacheId($file);
		if(($value = $store->get($cacheId)))
		{
			return $value;
		}
		
		$config = Parser::parse($file);
		if($config === null)
		{
			return null;
		}
		
		return new Environment($config);
	}
	
	/**
	 * Caches the Environment so later load() calls skip the parse.
	 */
	public function remember(
		string $file,
		Environment $environment,
		?string $cacheId = null,
	): void
	{
		$this->memoryService
			->getStore()
			->set($cacheId ?? static::cacheId($file), $environment);
	}
	
	/**
	 * Drops the remembered Environment, so the next load() parses the file.
	 */
	public function forget(
		string $file,
		?string $cacheId = null,
	): void
	{
		$this->memoryService
			->getStore()
			->delete($cacheId ?? static::cacheId($file));
	}
	
	protected static function cacheId(
		string $file,
	): string
	{
		return Normalizer::fromPath(basename($file));
	}
}
