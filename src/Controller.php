<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Controller\Plugin;
use Ovos\Exception\RuntimeException;
use Models;
use Plugins;
use ReflectionMethod;

/**
 * Controller
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Controller
{
	use Translatable;
	
	/**
	 * @var Application
	 */
	protected $_app;

	/**
	 * @var Request
	 */
	protected $_request;

	/**
	 * @var string
	 */
	protected $_dispatchedAction;

	/**
	 * @var bool
	 */
	protected $_dispatched = false;

	/**
	 * Params
	 *
	 * @var array
	 */
	protected $_params = [];

	/**
	 * Plugins
	 *
	 * @var array
	 */
	protected $_plugins = [];

	/**
	 */
	public function __construct()
	{
		$this->_app = Application::$instance ?: new Application;
		$this->_request = $this->_app->getRequest();
		$this->_request->setControllerInstance($this);

		$this->addTranslationPath();
		$this->registerSystemPlugins();
		$this->registerPlugins();
	}

	/**
	 * @param string $action
	 * @param array $params
	 *
	 * @return null|Response
	 */
	public function dispatch($action, $params): ?Response
	{
		$this->setDispatchedAction($action);

		$this->preDispatch();
		if($this->isDispatched())
		{
			return null;
		}
		
		// detect type of action argument, cast string to type if needed
		$method = new ReflectionMethod($this, $action);
		$parameters = $method->getParameters();
		foreach($parameters as $key => $parameter)
		{
			if(isset($params[$key]) && ($type = $parameter->getType()))
			{
				$typeName = $type->getName();
				if($typeName === 'int')
				{
					$params[$key] = (int)$params[$key];
				}
				else if($typeName === 'bool')
				{
					$params[$key] = (bool)$params[$key];
				}
			}
		}
		
		$response = $this->$action(...$params);
		if($response)
		{
			$this->_app->setResponse($response); // for postDispatch
		}
		$this->postDispatch();

		return $this->_app->getResponse();
	}

	/**
	 * @param string $dispatchedAction
	 *
	 * @return $this
	 */
	public function setDispatchedAction(string $dispatchedAction): self
	{
		$this->_dispatchedAction = $dispatchedAction;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getDispatchedAction(): string
	{
		return $this->_dispatchedAction;
	}

	/**
	 * @param array $params
	 *
	 * @return $this
	 */
	public function setParams($params): self
	{
		$this->_params = $params;

		return $this;
	}

	/**
	 * @return array
	 */
	public function getParams(): array
	{
		return $this->_params;
	}

	/**
	 * @param string $path (optional)
	 *
	 * @return $this
	 */
	public function addTranslationPath($path = null): self
	{
		$path = TRANSLATIONS_DIR . $path;
		$this->_request->getLocale()->getTranslator()->addTranslationPath($path);

		return $this;
	}
	
	/**
	 * preDispatch
	 */
	public function preDispatch(): void
	{
		$this->preDispatchPlugins();
	}

	/**
	 * postDispatch
	 */
	public function postDispatch(): void
	{
		$this->postDispatchPlugins();
	}

	/**
	 * Returns request
	 *
	 * @return Request
	 */
	public function getRequest(): Request
	{
		return $this->_app->getRequest();
	}

	/**
	 * Returns router
	 *
	 * @return Router
	 */
	public function getRouter(): Router
	{
		return $this->_app->getRouter();
	}

	/**
	 * @param bool $dispatched
	 *
	 * @return $this
	 */
	public function setDispatched(bool $dispatched): self
	{
		$this->_dispatched = $dispatched;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isDispatched(): bool
	{
		return $this->_dispatched;
	}

	/**
	 */
	public function registerSystemPlugins(): void
	{
		if($this->_app->getConfig()->system->plugins === null)
		{
			return;
		}

		$plugins = $this->_app->getConfig()->system->plugins
			->get($this->_app->getInterface());
		if($plugins === null)
		{
			return;
		}

		foreach($plugins as $plugin)
		{
			$pluginClass = strpos($plugin, '\\') === 0
				? $plugin : 'Plugins\\' . $plugin;

			if(!class_exists($pluginClass))
			{
				throw new RuntimeException('Plugin class does not exist "%s".', $pluginClass);
			}

			$this->addPlugin(new $pluginClass);
		}
	}

	/**
	 */
	public function registerPlugins(): void
	{
	}

	/**
	 * @param Plugin $plugin
	 * @param string $symbol
	 *
	 * @return $this
	 */
	public function addPlugin(Plugin $plugin, string $symbol = null): self
	{
		if($symbol === null)
		{
			$symbol = $plugin->getSymbol();
		}

		$this->_plugins[$symbol] = $plugin;

		return $this;
	}

	/**
	 * @param string $symbol
	 * @param array $arguments
	 *
	 * @return mixed|null
	 */
	public function getPlugin(string $symbol = null, array $arguments = [])
	{
		if($this->hasPlugin($symbol) === false)
		{
			return null;
		}

		$instance = $this->_plugins[$symbol];
		if(method_exists($instance, $symbol))
		{
			return $instance->$symbol($arguments);
		}
		
		return $instance;
	}

	/**
	 * @param string $symbol
	 *
	 * @return bool
	 */
	public function hasPlugin($symbol = null): bool
	{
		return array_key_exists($symbol, $this->_plugins);
	}

	/**
	 * @param string $symbol
	 * @param array $arguments
	 *
	 * @return mixed|null
	 */
	public function __call(string $symbol, array $arguments)
	{
		return $this->getPlugin($symbol, $arguments);
	}
	
	/**
	 * preDispatch
	 */
	public function preDispatchPlugins(): void
	{
		foreach($this->_plugins as $plugin)
		{
			if($this->isDispatched())
			{
				break;
			}

			if($plugin->isEnabled() === false)
			{
				continue;
			}

			$plugin->preDispatch();
		}
	}

	/**
	 * postDispatch
	 */
	public function postDispatchPlugins(): void
	{
		foreach($this->_plugins as $plugin)
		{
			if($this->isDispatched())
			{
				break;
			}

			if($plugin->isEnabled() === false)
			{
				continue;
			}

			$plugin->postDispatch();
		}
	}
}
