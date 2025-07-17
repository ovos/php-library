<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Controller\Plugin;
use Ovos\Container\Injector\TypeClass;
use Ovos\Exception\RuntimeException;
use ReflectionMethod;

use function count;
use function array_key_exists;
use function in_array;
use function array_column;
use function array_shift;
use function class_exists;
use function method_exists;
use function substr;
use function strpos;

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
	 * @var string
	 */
	public const string NAMESPACE = 'Controllers\\';
	
	/**
	 * @var string
	 */	
	public const string WILDCARD = '*';
	
	/**
	 * @var Container
	 */
	protected Container $_container;
	
	/**
	 * @var Application
	 */
	protected Application $_app;
	
	/**
	 * @var Request
	 */
	protected Request $_request;
	
	/**
	 * @var string
	 */
	protected string $_dispatchedAction;
	
	/**
	 * @var bool
	 */
	protected bool $_dispatched = false;
	
	/**
	 * Params
	 *
	 * @var array
	 */
	protected array $_params = [];
	
	/**
	 * Plugins
	 *
	 * @var array
	 */
	protected array $_plugins = [];
	
	/**
	 */
	public function __construct()
	{
		$this->_container = container();
		$this->_app = $this->_container
			->getClass(Application::class, Application::class);
		
		$this->_request = $this->_app->getRequest();
		$this->_request->setControllerInstance($this);
		
		$this->registerSystemPlugins();
		$this->registerPlugins();
	}
	
	/**
	 * @param string $action
	 * @param array $requestParams
	 *
	 * @return ?Response
	 */
	public function dispatch(string $action, array $requestParams = []): ?Response
	{
		$this->setDispatchedAction($action);
		
		$actionParams = $this->getActionParams($action, $requestParams);
		
		$this->preDispatch($actionParams);
		if($this->isDispatched())
		{
			return null;
		}
		
		$response = $this->$action(...$actionParams);
		if($response)
		{
			if(($response instanceof Response) === false)
			{
				$response = $this->_app->getResponse($response->__toString());
			}
		
			$this->_app->setResponse($response); // for postDispatch
		}
		$this->postDispatch();
		
		return $response;
	}
	
	/**
	 * @param string $action
	 * @param array $requestParams
	 *
	 * @return array
	 */
	public function getActionParams(string $action, array $requestParams = []): array
	{
		$count = count($requestParams);
		if($count === 0)
		{
			return [];
		}
		
		$method = new ReflectionMethod($this, $action);
		$methodParams = $method->getParameters();
		
		if($count === 1)
		{
			return $this->_getCastedParams($methodParams, $requestParams);
		}
		
		$namesOfMethodParams = array_column($methodParams, 'name');
		// slit params into unnamed and named
		$requestParamsUnnamed = $requestParamsNamed = [];
		while(($requestParam = array_shift($requestParams)) !== null)
		{
			if(is_string($requestParam)
				&& ($requestParamCamelCase = Strings::camelCase($requestParam))
				&& in_array($requestParamCamelCase, $namesOfMethodParams, true) === true
				&& ($requestParamValue = array_shift($requestParams)) !== null)
			{
				$requestParamsNamed[$requestParamCamelCase] = $requestParamValue;
			}
			else
			{
				$requestParamsUnnamed[] = $requestParam;
			}
		}
		
		// handle casting of unnamed
		$requestParamsUnnamed = $this->_getCastedParams($methodParams, $requestParamsUnnamed);
		// handle casting of named
		$requestParamsNamed = $this->_getCastedParams($methodParams, $requestParamsNamed, true);
		
		// merge them into one array that will be passed as params, first unnamed, then named
		return array_merge($requestParamsUnnamed, $requestParamsNamed);
	}
	
	/**
	 * @param array $methodParams
	 * @param array $requestParams
	 * @param bool $named
	 *
	 * @return array
	 */
	protected function _getCastedParams(
		array $methodParams, array $requestParams, bool $named = false): array
	{
		foreach($methodParams as $key => $methodParam)
		{
			$valueKey = $named ? $methodParam->name : $key;
			if(isset($requestParams[$valueKey])
				&& ($type = $methodParam->getType()))
			{
				$typeName = $type->getName();
				
				// int
				if($typeName === 'int'
					|| ($typeName === '?int'))
				{
					$requestParams[$valueKey] = (int)$requestParams[$valueKey];
				}
				
				// float
				if($typeName === 'float'
					|| ($typeName === '?float'))
				{
					$requestParams[$valueKey] = (float)$requestParams[$valueKey];
				}
			}
		}
		
		return $requestParams;
	}
	
	/**
	 * @param string $dispatchedAction
	 *
	 * @return self
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
	 * @return self
	 */
	public function setParams(array $params): self
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
	 * preDispatch
	 * 
	 * @param array $actionParams
	 */
	public function preDispatch(array $actionParams): void
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
	 * @return self
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
		$systemConfig = $this->_app->getConfig()->system;
		if($systemConfig->plugins === null)
		{
			return;
		}
		
		// fetch default plugins
		$plugins = $systemConfig->plugins->default
			->get($this->_app->getInterface());
		if($plugins === null)
		{
			return;
		}
		
		// handle adding & skipping of controller-specific plugins
		$groups = $systemConfig->plugins->groups;
		if($groups !== null)
		{
			$plugins = $this->getGroupsPlugins($plugins, $groups);
		}
		
		$this->_loadPluginsFromConfig($plugins);
	}
	
	/**
	 * @param ArrayObject $plugins
	 * @param ArrayObject $groups
	 * 
	 * @return ArrayObject
	 */
	public function getGroupsPlugins(ArrayObject $plugins,
		ArrayObject $groups
	): ArrayObject
	{
		if($groups->count() === 0)
		{
			return $plugins;
		}
		
		// name of the class without Controllers\ namespace
		$currentController = $this->getName();
		
		foreach($groups as $group)
		{
			$plugins = $this->getGroupPlugins($plugins, $group, $currentController);
		}
		
		return $plugins;
	}
	
	/**
	 * Name of the class without \Controllers\ namespace
	 * 
	 * @return string
	 */
	public function getName(): string
	{
		return substr(static::class,
			strpos(static::class, '\\') + 1);
	}
	
	/**
	 * @param ArrayObject $plugins
	 * @param ArrayObject $group
	 * @param string $currentController
	 * 
	 * @return ArrayObject
	 */
	public function getGroupPlugins(ArrayObject $plugins,
		ArrayObject $group,
		string $currentController
	): ArrayObject
	{
		if($group->controllers === null
			|| $group->controllers->count() === 0)
		{
			return $plugins;
		}
		
		foreach($group->controllers as $controller)
		{
			// if the controller matches (begins with the same name)
			if(str_starts_with($currentController, $controller) === false)
			{
				continue;
			}
			
			$controllerPlugins = $group->get($this->_app->getInterface());
			
			if($controllerPlugins !== null)
			{
				$plugins = $this->getControllerPlugins($plugins, $controllerPlugins);
			}
			
			break; // no need to check further
		}
		
		return $plugins;
	}
	
	/**
	 * @param ArrayObject $plugins
	 * @param ArrayObject $controllerPlugins
	 * 
	 * @return ArrayObject
	 */
	public function getControllerPlugins(ArrayObject $plugins,
		ArrayObject $controllerPlugins
	): ArrayObject
	{
		// skip
		if($controllerPlugins->skip !== null
			&& count($controllerPlugins->skip))
		{
			foreach($controllerPlugins->skip as $skip)
			{
				// avoid manipulating the current object during iteration 
				$offsetsToUnset = [];
				
				foreach($plugins as $offset => $plugin)
				{
					if($skip === self::WILDCARD) // skip all
					{
						$offsetsToUnset[] = $offset;
						
						continue;
					}
					
					if($skip === $plugin)
					{
						$offsetsToUnset[] = $offset;
						
						break; // no need to check further
					}
				}
				
				foreach($offsetsToUnset as $offsetToUnset)
				{
					$plugins->offsetUnset($offsetToUnset);
				}
			}
		}
		
		// add
		if($controllerPlugins->add !== null
			&& count($controllerPlugins->add))
		{
			foreach($controllerPlugins->add as $add)
			{
				$plugins->append($add);
			}
		}
		
		return $plugins;
	}
	
	/**
	 * @param ?ArrayObject $plugins
	 * 
	 * @return self
	 * 
	 * @throws RuntimeException
	 */
	protected function _loadPluginsFromConfig(?ArrayObject $plugins): self
	{
		if($plugins === null)
		{
			return $this;
		}
		
		foreach($plugins as $plugin)
		{
			$pluginClass = str_starts_with($plugin, '\\')
				? $plugin
				: 'Plugins\\' . $plugin;
			
			if(class_exists($pluginClass) === false)
			{
				throw new RuntimeException('Plugin class does not exist "%s".', $pluginClass);
			}
			
			/** @var Plugin $instance */
			$instance = $this->_container
				->inject(new TypeClass($pluginClass));
			$this->addPlugin($instance);
		}
		
		return $this;
	}
	
	/**
	 */
	public function registerPlugins(): void
	{
	}
	
	/**
	 * @param Plugin $plugin
	 * @param ?string $symbol
	 *
	 * @return self
	 */
	public function addPlugin(Plugin $plugin, ?string $symbol = null): self
	{
		if($symbol === null)
		{
			$symbol = $plugin->getSymbol();
		}
		
		$this->_plugins[$symbol] = $plugin;
		
		return $this;
	}
	
	/**
	 * @param ?string $symbol
	 * @param array $arguments
	 *
	 * @return mixed
	 */
	public function getPlugin(?string $symbol = null, array $arguments = []): mixed
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
	 * @param ?string $symbol
	 *
	 * @return bool
	 */
	public function hasPlugin(?string $symbol = null): bool
	{
		return array_key_exists($symbol, $this->_plugins);
	}
	
	/**
	 * @param string $symbol
	 * @param array $arguments
	 *
	 * @return mixed
	 */
	public function __call(string $symbol, array $arguments): mixed
	{
		return $this->getPlugin($symbol, $arguments);
	}
	
	/**
	 * @param string $symbol
	 *
	 * @return self
	 */
	public function removePlugin(string $symbol): self
	{
		unset($this->_plugins[$symbol]);
		
		return $this;
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
