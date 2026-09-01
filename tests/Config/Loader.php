<?php
declare(strict_types=1);

namespace Tests\Config;

use Ovos\ArrayObject;
use Ovos\Config\Loader as Subject;
use Ovos\Environment;
use Ovos\Exception\InvalidException\InvalidConfigException;
use Ovos\Exception\MissingException\MissingConfigException;
use Ovos\Exception\NotFoundException\FileNotFoundException;
use Ovos\Test;

use function str_contains;
use function uniqid;

/**
 * Config\Loader - the environment's section of a YAML config, and how each
 * way of getting it wrong is reported (a .env left on a retired environment
 * used to die as a TypeError naming neither the file nor the environment)
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Loader extends Test
{
	protected const string FILES = __DIR__ . '/files/';
	
	protected function subject(): Subject
	{
		return $this->container->getClass(Subject::class);
	}
	
	protected function environment(
		string $env,
	): Environment
	{
		return new Environment(['ENV' => $env]);
	}
	
	/**
	 * A fresh cache id per call: no run sees another run's entry
	 */
	protected function cacheId(): string
	{
		return 'tests-config-loader-' . uniqid();
	}
	
	public function knownEnvironmentYieldsItsSection(): bool
	{
		$config = $this->subject()->load(
			self::FILES . 'environments.yml',
			$this->environment('development'),
			$this->cacheId(),
		);
		
		return $config instanceof ArrayObject
			&& $config->system->path === '/'
			&& $config->system->debug === true;
	}
	
	public function unknownEnvironmentNamesTheFileTheDefinedSectionsAndTheEnvKey(): bool
	{
		try
		{
			$this->subject()->load(
				self::FILES . 'environments.yml',
				$this->environment('production-standalone'),
				$this->cacheId(),
			);
		}
		catch(MissingConfigException $e)
		{
			$message = $e->getMessage();
			
			return str_contains($message, '"production-standalone"')
				&& str_contains($message, 'environments.yml')
				&& str_contains($message, 'production, development')
				&& str_contains($message, 'ENV key in .env');
		}
		
		return false;
	}
	
	public function missingFileIsReportedByName(): bool
	{
		try
		{
			$this->subject()->load(
				self::FILES . 'nope.yml',
				$this->environment('production'),
				$this->cacheId(),
			);
		}
		catch(FileNotFoundException $e)
		{
			return str_contains($e->getMessage(), 'nope.yml');
		}
		
		return false;
	}
	
	public function invalidYamlIsReportedByName(): bool
	{
		try
		{
			$this->subject()->load(
				self::FILES . 'invalid.yml',
				$this->environment('production'),
				$this->cacheId(),
			);
		}
		catch(InvalidConfigException $e)
		{
			return str_contains($e->getMessage(), 'invalid.yml');
		}
		
		return false;
	}
}
