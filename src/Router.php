<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Exception\NotFoundException\FileNotFoundException;
use ReflectionClass;
use SplFileInfo;
use function is_string;
use function in_array;
use function array_slice;
use function is_numeric;
use function strcmp;
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
	 * @var string
	 */
	public const CACHE_ID_CONTROLLERS = 'controllers';

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
	 * @param Request $request
	 */
	public function __construct(Request $request)
	{
		$this->_app = app();
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
		
		if(in_array($matches[1], $this->_nonStaticExtensions))
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
			if($param === 'true')
			{
				$param = true;
			}
			else if($param === 'false')
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
		
		// after checking for locale, check for controller (with optional namespace path), and action
		foreach($params as $key => $param)
		{
			// determine the correct controller
			// controller namespace loop
			// while param is a valid namespace of controller, or a controller,
			// continue to the last matching one
			foreach($controllers as $name => $children)
			{
				if(is_string($name)
					&& isset($params[$key + 1])
					&& strcmp($name, $param) === 0
					&& (in_array($params[$key + 1], $children, true) // file
						|| array_key_exists($params[$key + 1], $children))) // dir
				{
					$controllers = $children; // loop children
					$controllerClass.= Strings::studlyCase($param) . '\\';
					$controller.= $param . '/';

					continue 2;
				}

				if(is_string($children)
					&& strcmp($children, $param) === 0)
				{
					break;
				}
			}

			$controller.= $param;
			$request->setController($controller);
			$controllerClass.= Strings::studlyCase($param);
			$request->setControllerClass($controllerClass);

			// cut out the controller and namespaces
			$params = array_slice($params, $key + 1);

			break;
		}

		// set action
		if(count($params))
		{
			if(is_numeric($params[0]))
			{
				return $params;
			}
			
			// check if controller has such method
			$method = Strings::camelCase($params[0]);
			$controllerClassNs = 'Controllers\\' . $controllerClass;
			$reflectionClass = new ReflectionClass($controllerClassNs);
			if($reflectionClass->hasMethod($method) === false)
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
		
		if($pool = services()->cache->getPerishablePool())
		{
			if($pool->hasItem($cacheId))
			{
				return $pool->getItem($cacheId)->get();
			}
		}
		
		$controllers = [];
		
		foreach($modules as $moduleName => $module)
		{
			$moduleDir = BASE_DIR . $module->path;
			if($module->controllers)
			{
				$dir = $moduleDir . DIRECTORY_SEPARATOR . 'controllers';
				
				$moduleControllers = Dir::getTree($dir, skipCallback: function($file)
				{
					/**
					* @var SplFileInfo $file
					*/
					return $file->getExtension() !== 'php';
				}, basenameCallback: function($file)
				{
					/**
					* @var SplFileInfo $file
					*/
					$basename = $file->getBasename('.php');
					return Strings::snakeCase($basename);
				});
				
				$controllers = Arrays::deepMerge($controllers, $moduleControllers);
				// directories (keys of array) first, ksort puts the directories last
				// order is z-a
				krsort($controllers, SORT_NATURAL);
			}
		}
		
		if($pool = services()->cache->getPerishablePool())
		{
			$item = $pool->getItem($cacheId)->set($controllers);
			$pool->save($item);
		}

		return $controllers;
	}
}
