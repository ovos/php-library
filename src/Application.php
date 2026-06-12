<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Environment\Loader as EnvLoader;
use Ovos\Exception\RuntimeException;
use Ovos\Config\Loader as ConfigLoader;
use Ovos\Pdo\Profiler\Reporter;
use Ovos\Response\Redirect;
use Ovos\Service\Memory;
use Throwable;

use function define;
use function sprintf;
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
 * @author Marcin Gil <mg@ovos.at>
 */
class Application
{
	public static ?Application $instance = null;
	
	// Interfaces
	public const string INT_HTTP = 'http';
	public const string INT_CLI = 'cli';
	
	// Container keys
	public const string CONTAINER_KEY_CONFIG = 'config';
	
	/**
	 * The configs fir
	 */
	public const string CONFIGS_DIR = BASE_DIR
		. 'application' . DIRECTORY_SEPARATOR
		. 'configs' . DIRECTORY_SEPARATOR;
	
	/**
	 * The environment state of the current application
	 */
	protected Environment $environment;
	
	/**
	 * The container for object instances of the current application
	 */
	protected ?Container $container = null;
	
	/**
	 * The interface of the current application
	 */
	protected string $interface = self::INT_HTTP;
	
	protected ?ArrayObject $config = null;
	
	/**
	 * @var ArrayObject[]
	 */
	protected array $configs = [];
	
	protected ?ArrayObject $bootstrap = null;
	
	protected ?string $domain = null;
	
	protected ?Request $request = null;
	
	protected ?Response $response = null;
	
	protected ?Router $router = null;
	
	public function __construct(
		?string $interface = null, // @see self::INT_*
	)
	{
		$this->init();
		
		if($interface !== null)
		{
			$this->interface = $interface;
		}
		else if($this->getRequest()->isCli())
		{
			$this->interface = self::INT_CLI;
		}
		
		$this
			->initEnvironment()
			->initShutdownHandler()
			->initBootstrap()
			->initDomain()
			->initConstants()
			->initProtocol()
			->initModules()
			->initServices();
	}
	
	public function run(): void
	{
		$request = $this->getRequest();
		
		$router = $this->getRouter();
		$router->route($request);
		
		$this->dispatch($request);
	}
	
	public function dispatch(
		Request $request,
	): void
	{
		try
		{
			$dispatcher = $this->container->getClass(Dispatcher::class);
			if($response = $dispatcher->dispatch($request))
			{
				$this->setResponse($response);
			}
		}
		catch(RuntimeException $exception)
		{
			$this->getServices()->events->add($exception);
		}
	}
	
	public function getRouter(): Router
	{
		if($this->router === null)
		{
			$this->router = $this->container
				->get(Router::class);
		}
		
		return $this->router;
	}
	
	public function getRequest(): Request
	{
		if($this->request === null)
		{
			$this->request = $this->container
				->get(Request::class);
		}
		
		return $this->request;
	}
	
	public function getResponse(
		?string $response = null,
	): Response
	{
		if($this->response === null)
		{
			$this->response = $this->request->isCli()
				? new Response\Cli($response)
				: new Response\Html($response);
		}
		else if($response !== null)
		{
			$this->response->set($response);
		}
		
		return $this->response;
	}
	
	public function setResponse(
		Response $response,
	): static
	{
		$this->response = $response;
		
		return $this;
	}
	
	protected function init(): static
	{
		self::$instance = $this->getContainer()
			->registerValue(__CLASS__, $this, true)
			->get(__CLASS__);
		
		$this->container
			// Request is required by Memory
			->registerClass(Request::class)
			->registerClass(Router::class)
			->registerClass(Memory::SYMBOL, Memory::class);
		
		return $this;
	}
	
	protected function initEnvironment(): static
	{
		// get environment from the file
		$environmentFile = BASE_DIR . Environment::FILE;
		$loader = $this->container
			->getClass(EnvLoader::class);
		
		$environment = $loader->load($environmentFile);
		$this->environment = $environment ?: new Environment;
		
		$configsDir = defined('CONFIGS_DIR')
			? constant('CONFIGS_DIR')
			: self::CONFIGS_DIR;
		
		$this->config = $this->getConfig(
			$configsDir . 'environments.yml',
			$environment,
		);
		$this->container
			->registerObject(Environment::class, $this->environment)
			->registerObject(self::CONTAINER_KEY_CONFIG, $this->config);
		
		return $this;
	}
	
	public function getEnvironment(): Environment
	{
		return $this->environment;
	}
	
	public function getEnv(): string
	{
		return $this->environment->getEnv();
	}
	
	public function setInterface(
		string $interface,
	): static
	{
		$this->interface = $interface;
		
		return $this;
	}
	
	public function getInterface(): string
	{
		return $this->interface;
	}
	
	public function isInterfaceHttp(): bool
	{
		return $this->interface === self::INT_HTTP;
	}
	
	public function isInterfaceCli(): bool
	{
		return $this->interface === self::INT_CLI;
	}
	
	/**
	 * Returns the config object (with optional array access)
	 */
	public function getConfig(
		?string $configFile = null,
		?Environment $environment = null,
	): ArrayObject
	{
		if($environment === null && $configFile === null)
		{
			return $this->config;
		}
		
		if(isset($this->configs[$configFile]) === false)
		{
			/** @var ConfigLoader $loader */
			$loader = $this->container->getClass(ConfigLoader::class, 
			ConfigLoader::class,
			);
			
			$config = $loader->load($configFile, $environment);
			$this->configs[$configFile] = $config;
		}
		
		return $this->configs[$configFile];
	}
	
	/**
	 * Sets up shutdown handler
	 */
	protected function initShutdownHandler(): static
	{
		register_shutdown_function(array($this, 'handleShutdown'));
		
		return $this;
	}
	
	/**
	 * Initializes the current bootstrap
	 */
	protected function initBootstrap(): static
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
	
	public function setBoostrap(
		ArrayObject $bootstrap,
	): static
	{
		$this->bootstrap = $bootstrap;
		if($controller = $this->bootstrap->controller
			->get($this->getInterface()))
		{
			$this->getRequest()
				->setController($controller)
				->setControllerClass(Strings::studlyCase($controller));
		}
		
		return $this;
	}
	
	public function getBootstrap(): ?ArrayObject
	{
		return $this->bootstrap;
	}
	
	/**
	 * Initializes the current domain
	 */
	protected function initDomain(): static
	{
		$systemConfig = $this->getConfig()->system;
		/** @var string $domain */
		$domain = $systemConfig->domain; // if there is just one
		/** @var ArrayObject $domains */
		$domains = $systemConfig->domains; // if there are many
		
		// CLI applications don't have domains
		if($domain === null
			&& $domains === null)
		{
			return $this;
		}
		
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
		
		if(isset($_SERVER['SERVER_NAME']) === false)
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
	
	public function setDomain(
		string $domain,
	): static
	{
		$this->domain = $domain;
		
		return $this;
	}
	
	public function getDomain(): ?string
	{
		return $this->domain;
	}
	
	/**
	 * Initializes constants
	 */
	protected function initConstants(): static
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
		
		// SYSTEM_HOST
		$systemHost = $domainWithPort !== null
			? sprintf('%s://%s', $systemConfig->protocol, $domainWithPort)
			: null;
		define('SYSTEM_HOST', $systemHost);
		// SYSTEM_HOST_HTTPS
		$systemHostHttps = $domainWithPort !== null
			? 'https://' . $domainWithPort
			: null;
		define('SYSTEM_HOST_HTTPS', $systemHostHttps);
		// SYSTEM_PATH
		define('SYSTEM_PATH', $systemPath);
		// ROUTE_PATH
		define('ROUTE_PATH', $routePath);
		// LOGS_DIR
		define('LOGS_DIR', BASE_DIR . 'application' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR);
		// RESOURCES_DIR
		define('RESOURCES_DIR', BASE_DIR . 'application' . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR);
		
		return $this;
	}
	
	/**
	 * Initializes protocol (http or https)
	 * Redirects to the correct protocol if needed
	 */
	protected function initProtocol(): static
	{
		if($this->isInterfaceHttp() === false)
		{
			return $this;
		}
		
		if(Client::getProtocol() !== $this->getConfig()->system->protocol)
		{
			$this->setResponse((new Redirect)
				->withHost()
				->withQueryString()
			);
			
			exit; // response is handled in handleShutdown()
		}
		
		return $this;
	}
	
	/**
	 * Initializes modules
	 */
	protected function initModules(): static
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
					. 'translations' . DIRECTORY_SEPARATOR
				);
			}
			
			if($module->views)
			{
				set_include_path
				(
					implode
					(
						PATH_SEPARATOR,
						[
							BASE_DIR . $module->path
							. DIRECTORY_SEPARATOR . 'views',
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
	 */
	protected function initServices(): static
	{
		/** @var Services $services */
		$services = $this->container
			->registerCallable(Services::class,
			function(Container $container)
			{
				$servicesClass = $container->get(self::CONTAINER_KEY_CONFIG)
					->system->services->container;
				if($servicesClass !== null)
				{
					$servicesClass = str_starts_with($servicesClass, '\\')
						? $servicesClass
						: 'Ovos\\' . $servicesClass;
					
					/**
					 * @var Services $servicesClass
					 */
					return new $servicesClass($container);
				}
				
				return new Services($container);
			})
			->get(Services::class);
		
		$servicesToRegister = $services->getConfig()
			->get($this->getInterface());
		if($servicesToRegister === null)
		{
			return $this;
		}
		
		foreach($servicesToRegister as $service)
		{
			$serviceClass = str_starts_with($service, '\\')
				? $service
				: 'Ovos\Service\\' . $service;
			
			if(class_exists($serviceClass) === false)
			{
				throw new RuntimeException(
					'Service class does not exist "%s".',
					$serviceClass,
				);
			}
			
			$services->register($serviceClass::SYMBOL, $serviceClass)
				->get($serviceClass::SYMBOL);
		}
		
		return $this;
	}
	
	public function getServices(): Services
	{
		return $this->container
			->getClass(Services::class);
	}
	
	public function handleShutdown(): void
	{
		if($error = error_get_last())
		{
			try
			{
				$this->getServices()->events->handleError(
					$error['type'],
					$error['message'],
					$error['file'],
					$error['line'],
				);
			}
			catch(Throwable $throwable)
			{
				// handleError() throws the converted ErrorException — during
				// shutdown that would abort this handler before the response
				// is sent; collect it like a regular event instead
				$this->getServices()->events
					->add($throwable)
					->log($throwable);
			}
		}
		
		// get the response to be sent
		$response = $this->getResponse();
		
		$hasEvents = $this->getServices()->events->count() > 0;
		
		// handle erroneous response
		if($hasEvents)
		{
			// set a default error code that can be overwritten by the events controller
			$response->setHttpCode(500);
			
			/// JSON
			if($this->getResponse() instanceof Response\Json)
			{
				if($this->getConfig()->system->debug)
				{
					$errors = [];
					foreach($this->getServices()->events as $event)
					{
						/** @var $event Exception */
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
					$this->getServices()->events->log($throwable);
				}
			}
		}
		
		// send the response
		try
		{
			$this->sendResponse($response);
		}
		catch(Throwable $throwable)
		{
			$this->getServices()->events->log($throwable);
		}
		
		// report collected events to the error console — after the
		// response, so the user never waits for it (best effort)
		try
		{
			$this->container
				->get(Service\Console\Sender::SYMBOL)
				->flush();
		}
		catch(Throwable)
		{
			// sender not registered or unavailable
		}
		
		if($hasEvents)
		{
			exit(1); // exit with error status for github actions
		}
	}
	
	public function getContainer(): Container
	{
		if($this->container === null)
		{
			$this->container = container();
		}
		
		return $this->container;
	}
	
	/**
	 * Sends a response
	 */
	protected function sendResponse(
		?Response $response,
	): static
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
			$this->sendJsonResponse($response);
		}
		else if($response instanceof Response\Html)
		{
			/*** @var Response\Html $response */
			$this->sendProfiledResponse($response);
		}
		else if($response instanceof Response\Cli)
		{
			/*** @var Response\Cli $response */
			$this->sendProfiledResponse($response);
		}
		else
		{
			/*** @var Response $response */
			$response->send();
		}
		
		return $this;
	}
	
	/**
	 * Sends a JSON response
	 */
	protected function sendJsonResponse(
		Response\Json $response,
	): static
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
	 * Sends a profiled response
	 */
	protected function sendProfiledResponse(
		Response $response,
	): static
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
 */
function app(): Application
{
	return Application::$instance;
}

/**
 * Returns the config array
 */
function config(): ArrayObject
{
	return app()->getConfig();
}

function request(): Request
{
	return app()->getRequest();
}

function locale(): Locale
{
	return app()->getRequest()->getLocale();
}

function console(
	...$messages,
): Console
{
	return container()
		->getClass(Console::class)
		->setMessages(...$messages);
}

function services(): Services
{
	return app()->getServices();
}