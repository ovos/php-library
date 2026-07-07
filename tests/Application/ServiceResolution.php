<?php
declare(strict_types=1);

// the fixture project namespace: a "\Tests\Application\Fixture\Services"
// container implies services live in Tests\Application\Fixture\Service\
namespace Tests\Application\Fixture\Service {
	class Auth
	{
		public const string SYMBOL = 'auth';
	}
}

namespace Tests\Application {
	
	use Ovos\Application;
	use Ovos\Exception\RuntimeException;
	use Ovos\Test;
	
	use function str_contains;
	
	/**
	 * ServiceResolution - how configured service names become classes
	 *
	 * @author Marcin Gil <mg@ovos.at>
	 */
	class ServiceResolution extends Test
	{
		protected const string CONTAINER = '\Tests\Application\Fixture\Services';
		
		public function absoluteNameIsUsedVerbatim(): bool
		{
			return Application::resolveServiceClass('\Ovos\Service\Cache')
				=== '\Ovos\Service\Cache';
		}
		
		public function bareNameResolvesInTheFrameworkNamespace(): bool
		{
			// no custom container - framework only
			return Application::resolveServiceClass('Cache')
				=== 'Ovos\Service\Cache'
				// the BASE container carries no project namespace either
				&& Application::resolveServiceClass('Cache', \Ovos\Services::class)
					=== 'Ovos\Service\Cache';
		}
		
		public function bareNameResolvesInTheProjectNamespaceFirst(): bool
		{
			// "Auth" + the fixture container finds the project's class -
			// no more "\Console\Service\Auth" spelled out in configs
			return Application::resolveServiceClass('Auth', self::CONTAINER)
				=== 'Tests\Application\Fixture\Service\Auth'
				// the container class name arrives without a leading
				// backslash from $services::class - both forms work
				&& Application::resolveServiceClass('Auth', 'Tests\Application\Fixture\Services')
					=== 'Tests\Application\Fixture\Service\Auth';
		}
		
		public function projectMissFallsBackToTheFramework(): bool
		{
			// the fixture namespace has no Cache - the framework's wins
			return Application::resolveServiceClass('Cache', self::CONTAINER)
				=== 'Ovos\Service\Cache';
		}
		
		public function unknownBareNameThrowsNamingBothCandidates(): bool
		{
			try
			{
				Application::resolveServiceClass('NoSuchService', self::CONTAINER);
			}
			catch(RuntimeException $exception)
			{
				return str_contains($exception->getMessage(),
						'Tests\Application\Fixture\Service\NoSuchService')
					&& str_contains($exception->getMessage(),
						'Ovos\Service\NoSuchService');
			}
			
			return false;
		}
		
		public function unknownAbsoluteNameThrows(): bool
		{
			try
			{
				Application::resolveServiceClass('\No\Such\Service');
			}
			catch(RuntimeException)
			{
				return true;
			}
			
			return false;
		}
	}
}
