<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Container\Inject;
use Ovos\Exception\NotFoundException\FileNotFoundException;
use Ovos\Exception\NotFoundException;
use Ovos\Service\Cache;
use SplFileInfo;

use function in_array;
use function array_slice;
use function is_numeric;
use function array_key_exists;
use function count;
use function krsort;
use function preg_match;

/**
 * Router
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Router
{
	/**
	 * @var Application
	 */
	protected Application $_app;
	
	/**
	 * @var ArrayObject
	 */
	protected ArrayObject $_config;
	
	/**
	 * @var Cache 
	 */
	#[Inject(Cache::SYMBOL)]
	protected Cache $_cacheService;
	
	/**
	 * @var string
	 */
	public const string CACHE_ID_CONTROLLERS = 'controllers';
	
	/**
	 * @var Request
	 */
	protected Request $_request;
	
	/**
	 * @var Url
	 */
	protected Url $_url;
	
	/**
	 * @var string
	 */
	protected string $_extensionMatchPattern = '~^.+(\.\w+)$~i';
	
	/**
	 * Don't treat these extensions as any other static file
	 *
	 * @var array
	 */
	protected array $_nonStaticExtensions = [
		'.xml',
		'.html',
	];
	
	/**
	 * @param Application $application
	 * @param Request $request
	 */
	public function __construct(Application $application,
		Request $request,
	)
	{
		$this->_app = $application;
		$this->_config = $this->_app->getConfig();
		
		$this->_request = $request;
		$this->_url = $request->getUrl();
	}
	
	/**
	 * @return Request
	 */
	public function getRequest(): Request
	{
		return $this->_request;
	}
	
	/**
	 * Accepts any number of url components and returns string
	 *
	 * @param string[] $components url components
	 *
	 * @return string
	 */
	public function assemble(...$components): string
	{
		$url = new Url(...$components);
		return $url->__toString();
	}
	
	/**
	 * Supports HTTP & CLI
	 * HTTP: /:controller/:action/[:paramValue/]+
	 * CLI: :controller :action [:paramValue]+
	 *
	 * @param Request $request
	 */
	public function route(Request $request): void
	{
		$params = $this->_url->getComponents();
		$this->_routeFiles($params);
		$this->_setRequest($request, $params);
	}
	
	/**
	 * Handle routing of files
	 * 
	 * @param array $params
	 *
	 * @throws FileNotFoundException
	 */
	protected function _routeFiles(array $params): void
	{
		if($this->_request->isCli())
		{
			return;
		}
		
		if(($count = count($params)) === 0)
		{
			return;
		}
		
		if(preg_match(
			$this->_extensionMatchPattern,
			$params[$count - 1],
			$matches
		) === 0)
		{
			return;
		}
		
		if(in_array($matches[1], $this->_nonStaticExtensions, true))
		{
			return;
		}
		
		$this->_url->setComponents([]);
		
		throw new FileNotFoundException('File not found.');
	}
	
	/**
	 * @param Request $request
	 * @param array $params
	 */
	protected function _setRequest(Request $request, array $params): void
	{
		$params = $this->_getParams($request, $params);
		
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
	
	/**
	 * @param Request $request
	 * @param array $params
	 *
	 * @return array
	 */
	protected function _getParams(Request $request, array $params): array
	{
		// set controller
		$controllerClass = $controller = null;
		
		$modules = $this->_config->system->modules;
		if($modules === null)
		{
			return $params;
		}
		
		$controllers = $this->_getControllers($modules);
		
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
				throw new NotFoundException('Class %s does not exist', $controllerClassNs);
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
	
	/**
	 * @param ArrayObject $modules
	 *
	 * @return array
	 */
	protected function _getControllers(ArrayObject $modules): array
	{
		$cacheId = self::CACHE_ID_CONTROLLERS;
		
		$store = $this->_cacheService->getPerishableStore();
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
						return $file->isFile() && $file->getExtension() !== 'php';
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
				$controllers = array_merge_recursive($controllers, $moduleControllers);
			}
		}
		
		// directories (keys of an array) first, ksort puts the directories last
		// order is z-a
		krsort($controllers, SORT_NATURAL);
		
		$store->set($cacheId, $controllers);
		
		return $controllers;
	}
}
