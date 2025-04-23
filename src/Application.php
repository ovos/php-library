<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Environment\Loader as EnvLoader;
use Ovos\Config\Loader as ConfigLoader;
use Ovos\Response\Redirect;
use Ovos\Service\Memory;
use Ovos\Pdo\Profiler\Reporter;
use Ovos\Exception\RuntimeException;
use Throwable;

use function define;
use function sprintf;
use function strpos;
use function str_starts_with;
use function array_merge;
use function array_unshift;
use function is_array;
use function count;
use function implode;
use function set_include_path;
use function get_include_path;
use function error_get_last;
use function register_shutdown_function;

/**
 * Application
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Application
{
	/**
	 * @var ?Application
	 */
	public static ?Application $instance = null;
	
	/**#@+
	 * Interface constants
	 */
	public const string INT_HTTP = 'http';
	public const string INT_CLI = 'cli';
	/**#@-*/
	
	/**
	 * The configs fir
	 *
	 * @var string
	 */
	public const string CONFIGS_DIR = BASE_DIR
		. 'application' . DIRECTORY_SEPARATOR
		. 'configs' . DIRECTORY_SEPARATOR;
	
	/**
	 * The environment state of the current application
	 *
	 * @var Environment
	 */
	protected Environment $_environment;
	
	/**
	 * The interface of the current application
	 *
	 * @var string
	 */
	protected string $_interface = self::INT_HTTP;
	
	/**
	 * @var ?ArrayObject
	 */
	protected ?ArrayObject $_config = null;
	
	/**
	 * @var ArrayObject[]
	 */
	protected array $_configs = [];
	
	/**
	 * @var ?ArrayObject
	 */
	protected ?ArrayObject $_bootstrap = null;
	
	/**
	 * @var ?string
	 */
	protected ?string $_domain = null;
	
	/**
	 * Request
	 *
	 * @var ?Request
	 */
	protected ?Request $_request = null;
	
	/**
	 * Response
	 *
	 * @var ?Response
	 */
	protected ?Response $_response = null;
	
	/**
	 * Router
	 *
	 * @var ?Router
	 */
	protected ?Router $_router = null;
	
	/**
	 * Construct
	 *
	 * @param ?string $interface (optional)
	 * @see self::INT_*
	 */
	public function __construct(?string $interface = null)
	{
		if($interface !== null)
		{
			$this->_interface = $interface;
		}
		else if($this->getRequest()->isCli())
		{
			$this->_interface = self::INT_CLI;
		}
		
		self::$instance = $this;
		
		$this->_init()
			->_initEnvironment()
			->_initShutdownHandler()
			->_initBootstrap()
			->_initDomain()
			->_initConstants()
			->_initProtocol()
			->_initModules()
			->_initServices();
	}
	
	/**
	 * Run
	 */
	public function run(): void
	{
		$request = $this->getRequest();
		
		$router = $this->getRouter();
		$router->route($request);
		
		$this->dispatch($request);
	}
	
	/**
	 * Dispatch
	 *
	 * @param Request $request
	 */
	public function dispatch(Request $request): void
	{
		try
		{
			$dispatcher = new Dispatcher;
			if($response = $dispatcher->dispatch($request))
			{
				$this->setResponse($response);
			}
		}
		catch(RuntimeException $exception)
		{
			services()->events->add($exception);
		}
	}
	
	/**
	 * Returns router
	 *
	 * @return Router
	 */
	public function getRouter(): Router
	{
		if($this->_router === null)
		{
			$this->_router = new Router($this->getRequest());
		}
		
		return $this->_router;
	}
	
	/**
	 * Returns request
	 *
	 * @return Request
	 */
	public function getRequest(): Request
	{
		if($this->_request === null)
		{
			$this->_request = new Request;
		}
		
		return $this->_request;
	}
	
	/**
	 * Returns response
	 * 
	 * @param ?string $response
	 *
	 * @return Response
	 */
	public function getResponse(?string $response = null): Response
	{
		if($this->_response === null)
		{
			$this->_response = $this->_request->isCli()
				? new Response\Cli($response)
				: new Response\Html($response);
		}
		else if($response !== null)
		{
			$this->_response->set($response);
		}
		
		return $this->_response;
	}
	
	/**
	 * Returns response
	 *
	 * @param Response $response
	 *
	 * @return self
	 */
	public function setResponse(Response $response): self
	{
		$this->_response = $response;
		
		return $this;
	}
	
	/**
	 * @return self
	 */
	protected function _init(): self
	{
		// register memory service manually for config loading
		Services::getInstance()->register(new Memory);
		
		return $this;
	}
	
	/**
	 * Sets the environment
	 *
	 * @return self
	 */
	protected function _initEnvironment(): self
	{
		// get environment from the file
		$environmentFile = BASE_DIR . Environment::ENV_FILE;
		$loader = new EnvLoader;
		$environment = $loader->load($environmentFile);
		$this->_environment = $environment ?: new Environment;
		
		$configsDir = defined('CONFIGS_DIR')
			? constant('CONFIGS_DIR')
			: self::CONFIGS_DIR;
		
		$this->_config = $this->getConfig(
			$configsDir . 'environments.yml',
			$environment
		);
		
		return $this;
	}
	
	/**
	 * Returns current environment
	 *
	 * @return Environment
	 */
	public function getEnvironment(): Environment
	{
		return $this->_environment;
	}
	
	/**
	 * Returns current environment as string
	 *
	 * @return string
	 */
	public function getEnv(): string
	{
		return $this->_environment->getEnv();
	}
	
	/**
	 * Sets the application's interface
	 *
	 * @param string $interface
	 *
	 * @return self
	 */
	public function setInterface(string $interface): self
	{
		$this->_interface = $interface;
		
		return $this;
	}
	
	/**
	 * Returns the application's interface
	 *
	 * @return string
	 */
	public function getInterface(): string
	{
		return $this->_interface;
	}
	
	/**
	 * @return bool
	 */
	public function isInterfaceHttp(): bool
	{
		return $this->_interface === self::INT_HTTP;
	}
	
	/**
	 * @return bool
	 */
	public function isInterfaceCli(): bool
	{
		return $this->_interface === self::INT_CLI;
	}
	
	/**
	 * Returns the config object (with optional array access)
	 *
	 * @param ?string $configFile
	 * @param ?Environment $environment
	 *
	 * @return ArrayObject
	 */
	public function getConfig(
		?string $configFile = null,
		?Environment $environment = null): ArrayObject
	{
		if($environment === null && $configFile === null)
		{
			return $this->_config;
		}
		
		if(!isset($this->_configs[$configFile]))
		{
			$loader = new ConfigLoader;
			$config = $loader->load($configFile, $environment);
			$this->_configs[$configFile] = $config;
		}
		
		return $this->_configs[$configFile];
	}
	
	/**
	 * Sets up shutdown handler
	 *
	 * @return self
	 */
	protected function _initShutdownHandler(): self
	{
		register_shutdown_function(array($this, 'handleShutdown'));
		
		return $this;
	}
	
	/**
	 * Initializes the current bootstrap
	 *
	 * @return self
	 */
	protected function _initBootstrap(): self
	{
		$systemConfig = $this->getConfig()->system;
		$bootstraps = $systemConfig->bootstraps;
		
		if($bootstraps === null)
		{
			return $this;
		}
		
		$iterator = $bootstraps->getIterator();
		$this->setBoostrap($iterator->current());
		
		if(count($bootstraps) === 1)
		{
			return $this;
		}
		
		if(!isset($_SERVER['REQUEST_URI']))
		{
			return $this;
		}
		
		// check from second bootstrap
		while($bootstrap = $iterator->current())
		{
			if(str_starts_with($_SERVER['REQUEST_URI'], 
				$systemConfig->path . $bootstrap->path))
			{
				$this->setBoostrap($bootstrap);
			}
			
			$iterator->next();
		}
		
		return $this;
	}
	
	/**
	 * @param ArrayObject $bootstrap
	 * 
	 * @return self
	 */
	public function setBoostrap(ArrayObject $bootstrap): self
	{
		$this->_bootstrap = $bootstrap;
		if($controller = $this->_bootstrap->controller->get($this->getInterface()))
		{
			$this->getRequest()->setController($controller);
			$this->getRequest()->setControllerClass(Strings::studlyCase($controller));
		}
		
		return $this;
	}
	
	/**
	 * @return ?ArrayObject
	 */
	public function getBootstrap(): ?ArrayObject
	{
		return $this->_bootstrap;
	}
	
	/**
	 * Initializes the current domain
	 *
	 * @return self
	 */
	protected function _initDomain(): self
	{
		$systemConfig = $this->getConfig()->system;
		/** @var string $domain */
		$domain = $systemConfig->domain; // if there is just one
		/** @var ArrayObject $domains */
		$domains = $systemConfig->domains; // if there are many
		
		if($domain !== null
			&& $domains === null)
		{
			$this->setDomain($domain);
			
			return $this;
		}
		
		if($domain !== null
			&& $domains !== null)
		{
			// merge into a single array object
			$domains = $domains->getArrayCopy();
			array_unshift($domains, $domain);
			$domains = new ArrayObject($domains);
		}
		
		$iterator = $domains->getIterator();
		$this->setDomain($iterator->current());
		
		if(count($domains) === 1)
		{
			return $this;
		}
		
		if(!isset($_SERVER['SERVER_NAME']))
		{
			return $this;
		}
		
		// compare SERVER_NAME against a list of domains in config and set the matching one
		while($current = $iterator->current())
		{
			if(str_contains($_SERVER['SERVER_NAME'], $current)) // in theory str_starts_with should be sufficient,
			// but we allow the case of misconfigured domains (e.g. www.domain.com instead of domain.com)
			{
				$this->setDomain($current);
			}
			
			$iterator->next();
		}
		
		return $this;
	}
	
	/**
	 * @param string $domain
	 * 
	 * @return self
	 */
	public function setDomain(string $domain): self
	{
		$this->_domain = $domain;
		
		return $this;
	}
	
	/**
	 * @return ?string
	 */
	public function getDomain(): ?string
	{
		return $this->_domain;
	}	
	
	/**
	 * Initializes constants
	 *
	 * @return self
	 */
	protected function _initConstants(): self
	{
		$systemConfig = $this->getConfig()->system;
		$bootstrap = $this->getBootstrap();
		$domain = $this->getDomain();
		
		$systemPath = $systemConfig->path;
		$routePath = $systemPath;
		
		if($systemConfig->route_path)
		{
			$routePath = $systemPath . $systemConfig->route_path;
		}
		
		if($bootstrap)
		{
			if($bootstrap->path)
			{
				$systemPath = $systemConfig->path . $bootstrap->path;
				$routePath = $systemPath;
			}
			
			if($bootstrap->route_path)
			{
				$routePath = $systemPath . $bootstrap->route_path;
			}
		}
		
		$domainWithPort = $domain;
		if($systemConfig->port)
		{
			$domainWithPort.= ':' . $systemConfig->port;
		}
		
		define('SYSTEM_HOST', sprintf('%s://%s', $systemConfig->protocol, $domainWithPort));
		define('SYSTEM_HOST_HTTPS', 'https://' . $domainWithPort);
		define('SYSTEM_PATH', $systemPath);
		define('ROUTE_PATH', $routePath);
		//define('TRANSLATIONS_DIR', BASE_DIR . 'application' . DIRECTORY_SEPARATOR . 'translations' .  DIRECTORY_SEPARATOR);
		define('LOGS_DIR', BASE_DIR . 'application' . DIRECTORY_SEPARATOR . 'logs' .  DIRECTORY_SEPARATOR);
		define('RESOURCES_DIR', BASE_DIR . 'application' . DIRECTORY_SEPARATOR . 'resources' .  DIRECTORY_SEPARATOR);
		
		return $this;
	}
	
	/**
	 * Initializes protocol (http or https)
	 * Redirects to correct protocol if needed
	 *
	 * @return self
	 */
	protected function _initProtocol(): self
	{
		if($this->isInterfaceHttp() === false)
		{
			return $this;
		}
		
		if(Client::getProtocol() !== $this->getConfig()->system->protocol)
		{
			$this->setResponse((new Redirect())
				->withHost()
				->withQueryString()
			);
			
			exit; // response is handled in handleShutdown()
		}
		
		return $this;
	}
	
	/**
	 * Initializes modules
	 *
	 * @return self
	 */
	protected function _initModules(): self
	{
		$modules = $this->getConfig()->system->modules;
		if($modules === null)
		{
			return $this;
		}
		
		foreach($modules as $module)
		{
			$module->path = Dir::preProcess($module->path);
			
			if($module->translations)
			{
				Translator::addTranslationsPath(BASE_DIR
					. $module->path . DIRECTORY_SEPARATOR 
					. 'translations' . DIRECTORY_SEPARATOR);
			}
			
			if($module->views)
			{
				set_include_path
				(
					implode
					(
						PATH_SEPARATOR,
						[
							BASE_DIR . $module->path . DIRECTORY_SEPARATOR . 'views',
							get_include_path()
						]
					)
				);
			}
		}
		
		return $this;
	}
	
	/**
	 * Initializes services
	 *
	 * @return self
	 */
	protected function _initServices(): self
	{
		$services = $this->getServices()
			->getConfig()
			->get($this->getInterface());
		if($services === null)
		{
			return $this;
		}
		
		foreach($services as $service)
		{
			$serviceClass = strpos($service, '\\') === 0
				? $service : 'Ovos\Service\\' . $service;
			
			if(!class_exists($serviceClass))
			{
				throw new RuntimeException('Service class does not exist "%s".', $serviceClass);
			}
			
			Services::getInstance()->register(new $serviceClass);
		}
		
		return $this;
	}
	
	/**
	 * Handles shutdown
	 *
	 * @return void
	 */
	public function handleShutdown(): void
	{
		if($error = error_get_last())
		{
			services()->events->handleError(
				$error['type'],
				$error['message'],
				$error['file'],
				$error['line']);
		}
		
		// get the response to be sent
		$response = $this->getResponse();
		
		$hasEvents = services()->events->count() > 0;
		
		// handle erroneous response
		if($hasEvents)
		{
			/// JSON
			if($this->getResponse() instanceof Response\Json)
			{
				if($this->getConfig()->system->debug)
				{
					$errors = [];
					foreach(services()->events as $event)
					{
						/** @var $event Exception|Error */
						$errors[] = $event->__toString();
					}
					/** @var Response\Json $response */
					if(isset($response->errors) && is_array($response->errors))
					{
						$response->errors = array_merge($response->errors, $errors);
					}
					else
					{
						$response->errors = $errors;
					}
				}
				
				$response->success = false;
			}
			
			// HTML, CLI or JSON with HTTP debug
			if($this->getResponse() instanceof Response\Html
				|| $this->getResponse() instanceof Response\Cli
				|| $this->getRequest()->isHttpDebug())
			{
				/** @var Response\Html $response */
				$output = (string)$response->send();
					
				$errorController = new \Controllers\System\Events;
				try
				{
					$response = $errorController->dispatch('index', [$output]);
				}
				catch(Throwable $throwable)
				{
					services()->events->log($throwable);
				}
			}
		}
		
		// send the response
		try
		{
			$this->_sendResponse($response);
		}
		catch(Throwable $throwable)
		{
			services()->events->log($throwable);
		}
		
		if($hasEvents)
		{
			exit(1); // exit with error status for github actions
		}
	}

	/**
	 * @return Services
	 */
	public function getServices(): Services
	{
		$servicesClass = $this->getConfig()->system->services->container;
		if($servicesClass !== null)
		{
			$servicesClass = str_starts_with($servicesClass, '\\')
				? $servicesClass : 'Ovos\\' . $servicesClass;
			
			/**
			 * @var Services $servicesClass
			 */
			return $servicesClass::newInstance();
		}
		
		return Services::newInstance();
	}
	
	/**
	 * Send response
	 *
	 * @param ?Response $response
	 *
	 * @return self
	 */
	protected function _sendResponse(?Response $response): self
	{
		if($response === null)
		{
			return $this;
		}
	
		if($response->isSent())
		{
			return $this;
		}
		
		if($response instanceof Response\Json)
		{
			/**
			 * @var Response\Json $response
			 */
			$this->_sendJsonResponse($response);
		}
		else if($response instanceof Response\Html)
		{
			/*** @var Response\Html $response */
			$this->_sendProfiledResponse($response);
		}
		else if($response instanceof Response\Cli)
		{
			/*** @var Response\Cli $response */
			$this->_sendProfiledResponse($response);
		}
		else
		{
			/*** @var Response $response */
			$response->send();
		}
		
		return $this;
	}
	
	/**
	 * Sends JSON response
	 *
	 * @param Response\Json $response
	 *
	 * @return self
	 */
	protected function _sendJsonResponse(Response\Json $response): self
	{
		$profilers = $this->getConfig()->system->profilers;
		
		if($profilers->enabled
			&& $this->isInterfaceHttp()
			&& $profilers->append->http)
		{
			$reporter = new Reporter;
			$report = $reporter->getReport();
			if(!empty($report))
			{
				$response->queries = $report;
			}
		}
		
		if($this->getRequest()->isHttpDebug())
		{
			$response->setOptions(JSON_PRETTY_PRINT);
		}
		$response->send();
		
		if($profilers->enabled
			&& $this->isInterfaceCli()
			&& $profilers->append->cli
		)
		{
			foreach($profilers->helpers as $helper)
			{
				echo View::$helper(); // calls __toString
			}
		}
		
		return $this;
	}
	
	/**
	 * Sends profiled response
	 *
	 * @param Response $response
	 *
	 * @return self
	 */
	protected function _sendProfiledResponse(Response $response): self
	{
		$response->send();
		
		$profilers = $this->getConfig()->system->profilers;
		if($profilers->enabled)
		{
			if(($this->isInterfaceHttp() && $profilers->append->http)
				|| ($this->isInterfaceCli() && $profilers->append->cli))
			{
				foreach($profilers->helpers as $helper)
				{
					echo View::$helper(); // calls __toString
				}
			}
		}
		
		return $this;
	}
}

/**
 * Helper function for accessing the app
 *
 * @return Application
 */
function app(): Application
{
	return Application::$instance;
}

/**
 * Returns the config array
 *
 * @return ArrayObject
 */
function config(): ArrayObject
{
	return app()->getConfig();
}

/**
 * @return Request
 */
function request(): Request
{
	return app()->getRequest();
}

/**
 * @return Locale
 */
function locale(): Locale
{
	return app()->getRequest()->getLocale();
}

/**
 * @param ...$messages
 *
 * @return Console
 */
function console(...$messages): Console
{
	return Console::getInstance()->setMessages(...$messages);
}

/**
 * @return Services
 */
function services(): Services
{
	return app()->getServices();
}
