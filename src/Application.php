<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\ArrayObject;
use Ovos\Config\Loader;
use Ovos\Service\Memory;
use Ovos\View\Layout;
use Ovos\Pdo\Profiler\Reporter;
use Ovos\Exception\RuntimeException;

/**
 * Application
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Application
{
	/**
	 * @var Application
	 */
	public static $instance;

	/**#@+
	 * Environment constants
	 */
	public const ENV_PRODUCTION = 'production';
	/**#@-*/

	/**#@+
	 * Interface constants
	 */
	public const INT_HTTP = 'http';
	public const INT_CLI = 'cli';
	/**#@-*/

	/**
	 * The environment state of current application
	 *
	 * @var string
	 */
	protected $_environment;

	/**
	 * The interface of current application
	 *
	 * @var int
	 */
	protected $_interface = self::INT_HTTP;

	/**
	 * @var ArrayObject
	 */
	protected $_config;

	/**
	 * @var ArrayObject[]
	 */
	protected $_configs = [];

	/**
	 * Request
	 *
	 * @var Request
	 */
	protected $_request;

	/**
	 * Response
	 *
	 * @var Response
	 */
	protected $_response;

	/**
	 * Router
	 *
	 * @var Router
	 */
	protected $_router;

	/**
	 * Construct
	 *
	 * @param int $interface (optional)
	 * @see self::INT_*
	 */
	public function __construct($interface = null)
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
			->_initConstants()
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
	 * @return Response
	 */
	public function getResponse(): Response
	{
		if($this->_response === null)
		{
			$this->_response = new Response\Html;
		}

		return $this->_response;
	}

	/**
	 * Returns response
	 *
	 * @param Response $response
	 *
	 * @return $this
	 */
	public function setResponse(Response $response): self
	{
		$this->_response = $response;

		return $this;
	}

	/**
	 * @return $this
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
	 * @return $this
	 */
	protected function _initEnvironment(): self
	{
		// get environment from file
		$environmentFile = BASE_DIR . 'env';
		$environment = file_exists($environmentFile) ? rtrim(file_get_contents($environmentFile)) : 'production';

		$this->_environment = $environment;
		$this->_config = $this->getConfig(BASE_DIR . 'application/configs/environments.yml', $environment);

		return $this;
	}

	/**
	 * Returns current environment
	 *
	 * @return string
	 */
	public function getEnvironment(): string
	{
		return $this->_environment;
	}

	/**
	 * Sets the application's interface
	 *
	 * @param string $interface
	 *
	 * @return $this
	 */
	public function setInterface($interface): self
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
	 * @param null|string $configFile
	 * @param null|string $rootSection
	 *
	 * @return ArrayObject
	 */
	public function getConfig(string $configFile = null, string $rootSection = null): ArrayObject
	{
		if($rootSection === null && $configFile === null)
		{
			return $this->_config;
		}

		if(!isset($this->_configs[$configFile]))
		{
			$loader = new Loader;
			$config = $loader->load($configFile, $rootSection);
			$this->_configs[$configFile] = $config;
		}

		return $this->_configs[$configFile];
	}

	/**
	 * Sets up shutdown handler
	 *
	 * @return $this
	 */
	protected function _initShutdownHandler(): self
	{
		register_shutdown_function(array($this, 'handleShutdown'));

		return $this;
	}

	/**
	 * Initializes constants
	 *
	 * @return $this
	 */
	protected function _initConstants(): self
	{
		$systemConfig = $this->getConfig()->system;
		\define('SYSTEM_HOST', sprintf('%s://%s', $systemConfig->protocol, $systemConfig->domain));
		\define('SYSTEM_PATH', $systemConfig->path);
		\define('ROUTE_PATH', $systemConfig->route_path ? $systemConfig->route_path : SYSTEM_PATH);
		\define('TRANSLATIONS_DIR', BASE_DIR . 'application' . DIRECTORY_SEPARATOR . 'translations' .  DIRECTORY_SEPARATOR);
		\define('RESOURCES_DIR', BASE_DIR . 'application' . DIRECTORY_SEPARATOR . 'resources' .  DIRECTORY_SEPARATOR);

		return $this;
	}

	/**
	 * Initializes services
	 *
	 * @return $this
	 */
	protected function _initServices(): self
	{
		$services = $this->getConfig()->system->services
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

		// handle erroneous response
		if(services()->events->count())
		{
			/// JSON
			if(\get_class($this->getResponse()) === Response\Json::class)
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
					if(isset($response->errors) && \is_array($response->errors))
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

			// HTML or JSON with HTTP debug
			if(\get_class($this->getResponse()) === Response\Html::class || $this->getRequest()->isHttpDebug())
			{
				/** @var Response\Html $response */
				$output = (string)$response->send();
				
				$errorController = new \Controllers\System\Events;
				$errorController->dispatch('index', [$output]);
			}
		}

		// send the response
		$this->_sendResponse($response);
	}

	/**
	 * @return Services
	 */
	public function getServices(): Services
	{
		$servicesClass = $this->getConfig()->system->services->container;
		if($servicesClass !== null)
		{
			$servicesClass = strpos($servicesClass, '\\') === 0
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
	 * @param Response $response
	 *
	 * @return $this
	 */
	protected function _sendResponse($response): self
	{
		if($response->isSent())
		{
			return $this;
		}

		if($response instanceof Response)
		{
			if(get_class($response) === Response\Json::class)
			{
				/**
				 * @var Response\Json $response
				 */
				$this->_sendJsonResponse($response);
			}
			else if(get_class($response) === Response\Html::class)
			{
				/*** @var Response\Html $response */
				$this->_sendHtmlResponse($response);
			}
			else
			{
				/*** @var Response $response */
				$response->send();
			}
		}
		else
		{
			/*** @var Response\Html $response */
			$this->_sendHtmlResponse($response);
		}

		return $this;
	}

	/**
	 * Sends JSON response
	 *
	 * @param Response\Json $response
	 *
	 * @return $this
	 */
	protected function _sendJsonResponse($response): self
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
	 * Sends Html response
	 *
	 * @param Response\Html $response
	 *
	 * @return $this
	 */
	protected function _sendHtmlResponse($response): self
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
 * @return Services
 */
function services(): Services
{
	return app()->getServices();
}