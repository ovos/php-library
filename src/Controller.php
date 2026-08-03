<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Controller\Plugin;
use Ovos\Exception\Priority;
use Ovos\Exception\RuntimeException;
use Ovos\Logger\Traits\LogsEvents;
use ReflectionMethod;
use Throwable;

use function array_column;
use function array_key_exists;
use function array_shift;
use function class_exists;
use function count;
use function in_array;
use function method_exists;
use function str_starts_with;
use function strpos;
use function substr;

/**
 * Controller
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Controller
{
	use LogsEvents;
	use Translatable;
	
	public const string NAMESPACE = 'Controllers\\';
	
	public const string WILDCARD = '*';
	
	protected Container $container;
	
	protected Application $app;
	
	protected Request $request;
	
	protected string $dispatchedAction;
	
	protected bool $dispatched = false;
	
	protected array $params = [];
	
	protected array $plugins = [];
	
	/**
	 */
	public function __construct()
	{
		$this->container = container();
		$this->app = $this->container
			->getClass(Application::class);
		
		$this->request = $this->app->getRequest();
		$this->request->setControllerInstance($this);
		
		$this->registerSystemPlugins();
		$this->registerPlugins();
	}
	
	public function dispatch(
		string $action,
		array $requestParams = [],
	): ?Response
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
				$response = $this->app->getResponse($response->__toString());
			}
		
			$this->app->setResponse($response); // for postDispatch
		}
		$this->postDispatch();
		
		return $response;
	}
	
	public function getActionParams(
		string $action,
		array $requestParams = [],
	): array
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
			return $this->getCastedParams($methodParams, $requestParams);
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
		$requestParamsUnnamed = $this->getCastedParams($methodParams, $requestParamsUnnamed);
		// handle casting of named
		$requestParamsNamed = $this->getCastedParams($methodParams, $requestParamsNamed, true);
		
		// merge them into one array that will be passed as params, first unnamed, then named
		return array_merge($requestParamsUnnamed, $requestParamsNamed);
	}
	
	protected function getCastedParams
	(
		array $methodParams,
		array $requestParams,
		bool $named = false,
	): array
	{
		foreach($methodParams as $key => $methodParam)
		{
			$valueKey = $named ? $methodParam->name : $key;
			if(isset($requestParams[$valueKey])
				&& ($type = $methodParam->getType()))
			{
				$typeName = $type->getName();
				
				if($typeName === 'int')
				{
					$requestParams[$valueKey] = (int)$requestParams[$valueKey];
				}
				else if($typeName === 'float')
				{
					$requestParams[$valueKey] = (float)$requestParams[$valueKey];
				}
				else if($typeName === 'bool')
				{
					$requestParams[$valueKey] = (bool)$requestParams[$valueKey];
				}
			}
		}
		
		return $requestParams;
	}
	
	public function setDispatchedAction(
		string $dispatchedAction,
	): static
	{
		$this->dispatchedAction = $dispatchedAction;
		
		return $this;
	}
	
	public function getDispatchedAction(): string
	{
		return $this->dispatchedAction;
	}
	
	public function setParams(
		array $params,
	): static
	{
		$this->params = $params;
		
		return $this;
	}
	
	public function getParams(): array
	{
		return $this->params;
	}
	
	public function preDispatch(
		array $actionParams,
	): void
	{
		$this->preDispatchPlugins();
	}
	
	public function postDispatch(): void
	{
		$this->postDispatchPlugins();
	}
	
	public function getRequest(): Request
	{
		return $this->app->getRequest();
	}
	
	public function getRouter(): Router
	{
		return $this->app->getRouter();
	}
	
	public function setDispatched(
		bool $dispatched,
	): static
	{
		$this->dispatched = $dispatched;
		
		return $this;
	}
	
	public function isDispatched(): bool
	{
		return $this->dispatched;
	}
	
	public function registerSystemPlugins(): void
	{
		$systemConfig = $this->app->getConfig()->system;
		if($systemConfig->plugins === null)
		{
			return;
		}
		
		// fetch default plugins
		$plugins = $systemConfig->plugins->default
			->get($this->app->getInterface());
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
		
		$this->loadPluginsFromConfig($plugins);
	}
	
	public function getGroupsPlugins(
		ArrayObject $plugins,
		ArrayObject $groups,
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
			$plugins = $this->getGroupPlugins(
				$plugins,
				$group,
				$currentController,
			);
		}
		
		return $plugins;
	}
	
	/**
	 * The dispatch lifecycle by NAME — never an action, whoever declares it.
	 * An APPLICATION override moves a hook's declaring class out of the
	 * framework, so the family rule in resolveActionMethod() cannot see one;
	 * these three names are the dispatch contract itself — fixed, not a list
	 * that grows.
	 */
	protected const array HOOKS = ['dispatch', 'preDispatch', 'postDispatch'];
	
	/**
	 * The canonical method name for a URL action, or null when the segment
	 * must not dispatch.
	 *
	 * Two rules, both learned the hard way:
	 *
	 * 1. PUBLIC ONLY. method_exists() answers true for protected methods, and
	 *    the call is made from inside this class — an ancestor of every
	 *    controller — so PHP happily invokes them. Every protected helper on
	 *    every controller was therefore a URL-addressable endpoint, and a
	 *    codebase that avoids `private` (house style) has nothing but those.
	 *
	 * 2. NOT FRAMEWORK-DECLARED. dispatch() is public, so /<controller>/
	 *    dispatch/<action> re-entered the dispatcher and ran <action> while
	 *    the request still reported "dispatch" as its action — the string
	 *    authorization plugins look up. Every list-based admin or demo guard
	 *    was bypassable that way. The exclusion covers the whole Controller
	 *    FAMILY (this class and Controller\*), not just this class:
	 *    Controller\Cli declares ten public plumbing methods of its own, and
	 *    its preDispatch OVERRIDE had moved that hook's declaring class off
	 *    the base — quietly re-exposing it on every CLI controller. No
	 *    framework controller base contributes actions; the whole public
	 *    surface is plumbing. The family is also exactly as far as the rule
	 *    CAN see: only controllers dispatch, and single inheritance pins
	 *    every ancestor of a dispatched class to this family — so the rule
	 *    claims no more than its callers guarantee. (Module controllers live
	 *    in the application namespace and are untouched.)
	 *
	 * 3. NEVER THE HOOKS. dispatch/preDispatch/postDispatch are refused by
	 *    NAME, whoever declares them — an application override is dispatch
	 *    machinery all the same. Compared on the reflected name, which is the
	 *    declared spelling, so a lowercase probe cannot slip past.
	 *
	 * The returned name is the DECLARED spelling. PHP dispatches methods
	 * case-insensitively while guard maps compare strings, so /errors/deletE
	 * used to reach delete() past a map that lists "delete" — canonicalising
	 * here means the rest of the stack only ever sees one spelling.
	 */
	public static function resolveActionMethod(
		string $controllerClass,
		string $method,
	): ?string
	{
		if($method === '' || method_exists($controllerClass, $method) === false)
		{
			return null;
		}
		
		$reflection = new ReflectionMethod($controllerClass, $method);
		$declaring = $reflection->getDeclaringClass()->getName();
		
		if($reflection->isPublic() === false
			|| $reflection->isStatic()
			|| in_array($reflection->getName(), self::HOOKS, true)
			|| $declaring === self::class
			|| str_starts_with($declaring, self::class . '\\'))
		{
			return null;
		}
		
		return $reflection->getName();
	}
	
	public function getName(): string
	{
		return substr(static::class,
			strpos(static::class, '\\') + 1);
	}
	
	public function getGroupPlugins(
		ArrayObject $plugins,
		ArrayObject $group,
		string $currentController,
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
			
			$controllerPlugins = $group->get($this->app->getInterface());
			
			if($controllerPlugins !== null)
			{
				$plugins = $this->getControllerPlugins(
					$plugins,
					$controllerPlugins,
				);
			}
			
			break; // no need to check further
		}
		
		return $plugins;
	}
	
	public function getControllerPlugins(
		ArrayObject $plugins,
		ArrayObject $controllerPlugins,
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
	
	protected function loadPluginsFromConfig(
		?ArrayObject $plugins,
	): static
	{
		if($plugins === null)
		{
			return $this;
		}
		
		foreach($plugins as $plugin)
		{
			/** @var Plugin $instance */
			$instance = $this->container
				->injectClass(self::resolvePluginClass($plugin));
			$this->addPlugin($instance);
		}
		
		return $this;
	}
	
	/**
	 * A configured plugin name to its class: a leading "\" is an absolute
	 * FQCN; a bare name resolves in the application's Plugins\ namespace
	 * first (so an app can override), then falls back to the framework's
	 * Ovos\Plugins\ - the same cascade view helpers use. So the config can
	 * say "Security\Csrf" instead of "\Ovos\Plugins\Security\Csrf".
	 */
	public static function resolvePluginClass(
		string $plugin,
	): string
	{
		if(str_starts_with($plugin, '\\') === true)
		{
			if(class_exists($plugin) === false)
			{
				throw new RuntimeException(
					'Plugin class does not exist "%s".',
					$plugin,
				);
			}
			
			return $plugin;
		}
		
		$application = 'Plugins\\' . $plugin;
		if(class_exists($application) === true)
		{
			return $application;
		}
		
		$framework = 'Ovos\Plugins\\' . $plugin;
		if(class_exists($framework) === true)
		{
			return $framework;
		}
		
		throw new RuntimeException(
			'Plugin class does not exist: tried "%s" and "%s".',
			$application,
			$framework,
		);
	}
	
	public function registerPlugins(): void
	{
	}
	
	public function addPlugin(
		Plugin $plugin,
		?string $symbol = null,
	): static
	{
		if($symbol === null)
		{
			$symbol = $plugin->getSymbol();
		}
		
		$this->plugins[$symbol] = $plugin;
		
		return $this;
	}
	
	public function getPlugin(
		?string $symbol = null,
		array $arguments = [],
	): mixed
	{
		if($this->hasPlugin($symbol) === false)
		{
			return null;
		}
		
		$instance = $this->plugins[$symbol];
		if(method_exists($instance, $symbol))
		{
			return $instance->$symbol($arguments);
		}
		
		return $instance;
	}
	
	public function hasPlugin(
		?string $symbol = null,
	): bool
	{
		return array_key_exists($symbol, $this->plugins);
	}
	
	public function __call(
		string $symbol,
		array $arguments,
	): mixed
	{
		if($this->hasPlugin($symbol) === false)
		{
			// almost always a typo'd method name ($this->output() vs log());
			// stays null-returning — probing an optional plugin through its
			// magic accessor is a documented pattern — but no longer silent:
			// the null otherwise vanishes into business logic undebuggably.
			// Per-request optional probes belong on getPlugin() directly.
			$this->logUnknownSymbol($symbol);
			
			return null;
		}
		
		return $this->getPlugin($symbol, $arguments);
	}
	
	/**
	 * Best-effort — a missing Events service must not turn a typo into a
	 * fatal, and the log call itself must never recurse or throw
	 */
	protected function logUnknownSymbol(
		string $symbol,
	): void
	{
		try
		{
			$this->logEvent('Unknown method/plugin "%s" called on %s (returned null — typo?)',
				$symbol,
				static::class,
				priority: Priority::WARNING);
		}
		catch(Throwable)
		{
			// stay quiet rather than break the caller
		}
	}
	
	public function removePlugin(
		string $symbol,
	): static
	{
		unset($this->plugins[$symbol]);
		
		return $this;
	}
	
	public function preDispatchPlugins(): void
	{
		foreach($this->plugins as $plugin)
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
	
	public function postDispatchPlugins(): void
	{
		foreach($this->plugins as $plugin)
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
