<?php
declare(strict_types=1);

namespace Ovos\Environment;

use Ovos\ArrayObject;
use Ovos\Environment;
use Ovos\Service\Memory;
use Ovos\Container\Inject;
use Ovos\Cache\Key\Normalizer;

use function basename;
use function filemtime;
use function is_file;

/**
 * Loader
 *
 * Reads a .env file into an Environment. The parsed object is NOT cached by
 * load() itself: the caller decides, via remember(), once the environment has
 * proven usable (its section exists in environments.yml). The remembered entry
 * carries the file's mtime, as Config\Loader's does: an edited .env is read on
 * the next request. It used to wait for an APCu clear — and the clear is a
 * self-call signed with a key FROM .env, so a new key could not arrive by the
 * one path that would have delivered it.
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
		$item = $store->get($cacheId);
		// an entry from before the mtime (a bare Environment) is no hit either
		if($item instanceof ArrayObject
			&& $item->environment instanceof Environment
			&& $item->mtime === static::mtime($file))
		{
			return $item->environment;
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
		$item = new ArrayObject;
		$item->mtime = static::mtime($file);
		$item->environment = $environment;
		
		$this->memoryService
			->getStore()
			->set($cacheId ?? static::cacheId($file), $item);
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
	
	/**
	 * The file's mtime, false while it does not exist — never a warning
	 */
	protected static function mtime(
		string $file,
	): int|false
	{
		return is_file($file)
			? filemtime($file)
			: false;
	}
}
