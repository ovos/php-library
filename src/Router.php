<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Container\Inject;
use Ovos\Exception\NotFoundException\FileNotFoundException;
use Ovos\Exception\NotFoundException;
use Ovos\Route\Resolver;
use Ovos\Route\Resolution;
use Ovos\Service\Cache;
use SplFileInfo;

use function array_key_exists;
use function array_slice;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_numeric;
use function krsort;
use function preg_match;

/**
 * Router
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Router
{
	protected Application $app;
	
	protected ArrayObject $config;
	
	#[Inject(Cache::SYMBOL)]
	protected Cache $cacheService;
	
	public const string CACHE_ID_CONTROLLERS = 'controllers';
	
	protected Request $request;
	
	protected Url $url;
	
	/**
	 * A canonical redirect a resolver asked for; Application sends it as
	 * a 301 before dispatch
	 */
	protected ?Url $redirect = null;
	
	protected string $extensionMatchPattern = '~^.+(\.\w+)$~i';
	
	/**
	 * Don't treat these extensions as any other static file
	 */
	protected array $nonStaticExtensions = [
		'.xml',
		'.html',
	];
	
	public function __construct(
		Application $application,
		Request $request,
	)
	{
		$this->app = $application;
		$this->config = $this->app->getConfig();
		
		$this->request = $request;
		$this->url = $request->getUrl();
	}
	
	public function getRequest(): Request
	{
		return $this->request;
	}
	
	/**
	 * Accepts any number of url components and returns string
	 */
	public function assemble(
		...$components, // url components
	): string
	{
		$url = new Url(...$components);
		return $url->__toString();
	}
	
	/**
	 * Supports HTTP & CLI
	 * HTTP: /:controller/:action/[:paramValue/]+
	 * CLI: :controller :action [:paramValue]+
	 */
	public function route(
		Request $request,
	): void
	{
		// custom resolvers get first say; the convention routing below is
		// the terminal DEFAULT when none of them claim the url
		if($this->resolveCustom($request) === true)
		{
			return;
		}
		
		$params = $this->url->getComponents();
		$this->routeFiles($params);
		$this->setRequest($request, $params);
	}
	
	/**
	 * The canonical redirect a resolver requested, if any (Application
	 * turns it into a 301 before dispatch)
	 */
	public function getRedirect(): ?Url
	{
		return $this->redirect;
	}
	
	/**
	 * Runs the configured resolver chain (config "system.routes.resolvers":
	 * a list of Resolver class names). The first Resolution wins - it is
	 * applied to the request (or recorded as a redirect); returns whether
	 * the url was claimed. No resolvers configured = false, so the
	 * convention router handles everything exactly as before.
	 */
	protected function resolveCustom(
		Request $request,
	): bool
	{
		$resolvers = $this->config
			->getPath(['system', 'routes', 'resolvers']);
		if($resolvers === null)
		{
			return false;
		}
		
		foreach($resolvers as $resolverClass)
		{
			/** @var Resolver $resolver */
			$resolver = $this->app->getContainer()
				->getClass((string)$resolverClass);
			if($resolver instanceof Resolver === false)
			{
				continue;
			}
			
			$resolution = $resolver->resolve($this->url, $request);
			if($resolution === null)
			{
				continue;
			}
			
			$this->applyResolution($request, $resolution);
			
			return true;
		}
		
		return false;
	}
	
	protected function applyResolution(
		Request $request,
		Resolution $resolution,
	): void
	{
		if($resolution->isRedirect() === true)
		{
			$this->redirect = $resolution->redirect;
			
			return;
		}
		
		$controllerClass = $resolution->controllerClass;
		$request->setControllerClass($controllerClass);
		$request->setController($this->controllerPath($controllerClass));
		$request->setAction($resolution->action);
		$request->setActionMethod($resolution->action);
		
		foreach($resolution->params as $param)
		{
			$request->addParam($param);
		}
	}
	
	/**
	 * The dispatch controller path ("Blog\Article" -> "blog/article")
	 * for the request's controller name
	 */
	protected function controllerPath(
		string $controllerClass,
	): string
	{
		$segments = [];
		foreach(explode('\\', $controllerClass) as $segment)
		{
			$segments[] = Strings::snakeCase($segment);
		}
		
		return implode('/', $segments);
	}
	
	/**
	 * Handle routing of files
	 */
	protected function routeFiles(
		array $params,
	): void
	{
		if($this->request->isCli())
		{
			return;
		}
		
		if(($count = count($params)) === 0)
		{
			return;
		}
		
		if(preg_match(
			$this->extensionMatchPattern,
			$params[$count - 1],
			$matches,
		) === 0)
		{
			return;
		}
		
		if(in_array($matches[1], $this->nonStaticExtensions, true))
		{
			return;
		}
		
		$this->url->setComponents([]);
		
		throw new FileNotFoundException('File not found.');
	}
	
	protected function setRequest(
		Request $request,
		array $params,
	): void
	{
		$params = $this->getParams($request, $params);
		
		// set params on $request object
		foreach($params as $param)
		{
			if($param === 'true'
				|| $param === 'yes')
			{
				$param = true;
			}
			else if($param === 'false'
				|| $param === 'no')
			{
				$param = false;
			}
			else if($param === 'null')
			{
				$param = null;
			}
			
			$request->addParam($param);
		}
	}
	
	protected function getParams(
		Request $request,
		array $params,
	): array
	{
		// set controller
		$controllerClass = $controller = null;
		
		$modules = $this->config->system->modules;
		if($modules === null)
		{
			return $params;
		}
		
		$controllers = $this->getControllers($modules);
		
		// after checking for locale, check for controller (with an optional namespace path), and action
		foreach($params as $key => $param)
		{
			// determine the correct controller
			// check if we should go deeper (current param is not the last level controller, but a namespace)
			if(isset($controllers[$param]) 
				&& is_array($controllers[$param]) // namespace exists on this level
				&& isset($params[$key + 1])) // next param exists
			{
				$children = $controllers[$param];
				$nextParam = $params[$key + 1];
				
				// check if the next param is valid in this namespace
				if(in_array($nextParam, $children, true) // controller exists in the namespace
					|| array_key_exists($nextParam, $children)) // a deeper namespace exists inside the namespace
				{
					$controllers = $children; // loop children
					$controllerClass.= Strings::studlyCase($param) . '\\';
					$controller.= $param . '/';
					
					continue; // go to the next param
				}
			}
			
			// all possible namespaces added, add the controller
			if(in_array($param, $controllers, true)) // do not set non-existing controllers, use default instead
			{
				$controller.= $param;
				$request->setController($controller);
				$controllerClass.= Strings::studlyCase($param);
				$request->setControllerClass($controllerClass);
				
				// cut out the controller and namespaces
				$params = array_slice($params, $key + 1);
			}
			
			break; // last processed param that could be a controller
		}
		
		// there are more params left than controller namespace + name,
		// possibly action or action method value
		if(count($params))
		{
			if(is_numeric($params[0]))
			{
				return $params; // not an action for sure
			}
			
			// check if the controller has such a method
			$method = Strings::camelCase($params[0]);
			$controllerClass = $controllerClass ?? $request->getControllerClass(); // get default
			$controllerClassNs = 'Controllers\\' . $controllerClass;
			
			if(class_exists($controllerClassNs) === false)
			{
				throw new NotFoundException(
				'Class %s does not exist', $controllerClassNs);
			}
			
			if(method_exists($controllerClassNs, $method) === false)
			{
				return $params;
			}
			
			$action = array_shift($params);
			$request->setAction($action);
			$request->setActionMethod($method);
		}
		
		// return the remaining parameters
		return $params;
	}
	
	protected function getControllers(
		ArrayObject $modules,
	): array
	{
		$cacheId = self::CACHE_ID_CONTROLLERS;
		
		$store = $this->cacheService->getPerishable()
			->getStore();
		if($item = $store->get($cacheId))
		{
			return $item;
		}
		
		$controllers = [];
		
		foreach($modules as $moduleName => $module)
		{
			$moduleDir = BASE_DIR . $module->path;
			if($module->controllers)
			{
				$dir = $moduleDir . DIRECTORY_SEPARATOR . 'controllers';
				
				$moduleControllers = Dir::getTree(
					$dir,
					skipCallback: static function($file)
					{
						/**
						* @var SplFileInfo $file
						*/
						return $file->isFile()
							&& $file->getExtension() !== 'php';
					},
					filenameCallback: static function($file)
					{
						/**
						* @var SplFileInfo $file
						*/
						$basename = $file->getBasename('.php');
						return Strings::snakeCase($basename);
					}
				);
				
				// merge all values without overwriting keys like in Arrays::deepMerge
				$controllers = array_merge_recursive(
					$controllers,
					$moduleControllers,
				);
			}
		}
		
		// directories (keys of an array) first, ksort puts the directories last
		// order is z-a
		krsort($controllers, SORT_NATURAL);
		
		$store->set($cacheId, $controllers);
		
		return $controllers;
	}
}
