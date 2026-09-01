<?php
declare(strict_types=1);

namespace Ovos\Config;

use Ovos\Arrays;
use Ovos\ArrayObject;
use Ovos\Cache\Key\Normalizer;
use Ovos\Container\Inject;
use Ovos\Environment;
use Ovos\Exception\InvalidException\InvalidConfigException;
use Ovos\Exception\MissingException\MissingConfigException;
use Ovos\Exception\NotFoundException\FileNotFoundException;
use Ovos\Service\Memory;
use ErrorException;

use function array_keys;
use function basename;
use function filemtime;
use function implode;
use function is_file;

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
	
	/**
	 * Loads the section of a YAML config file named by the environment.
	 *
	 * Every way this can fail is a configuration mistake, and each one is
	 * reported with the file it concerns - a null return would only surface
	 * later as a TypeError that names neither the file nor the environment.
	 *
	 * @throws FileNotFoundException the file does not exist
	 * @throws InvalidConfigException the file is not valid YAML
	 * @throws MissingConfigException the file has no section for the environment
	 */
	public function load(
		string $file,
		Environment $environment,
		?string $cacheId = null,
	): ArrayObject
	{
		if(is_file($file) === false)
		{
			throw new FileNotFoundException(
				'Config file %s not found',
				$file,
			);
		}
		
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
		
		// at boot the parser's warning is just a warning and the false
		// return says it all; with the Events handler installed (a config
		// loaded later) the warning arrives as an ErrorException instead -
		// either way the report names the file, and libyaml's own message
		// carries the line
		try
		{
			$config = Parser::parse($file, $environment);
		}
		catch(ErrorException $e)
		{
			throw new InvalidConfigException(
				'Config file %s is not valid YAML: %s',
				$file,
				$e->getMessage(),
			);
		}
		
		if($config === null)
		{
			throw new InvalidConfigException(
				'Config file %s is not valid YAML',
				$file,
			);
		}
		
		if(isset($config[$rootSection]) === false)
		{
			// the typical cause is a .env left behind by a retired
			// environment - name what the file does define so the fix is
			// obvious from the first line of the error (which PHP suffixes
			// with "in file:line", hence the closing parenthesis)
			$defined = implode(', ', array_keys($config));

			throw new MissingConfigException(
				'Environment "%s" (%s key in %s) is not defined in %s (defined: %s)',
				$rootSection,
				Environment::KEY,
				Environment::FILE,
				$file,
				$defined === '' ? 'nothing' : $defined,
			);
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
