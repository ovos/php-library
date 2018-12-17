<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Dir;
use ReflectionClass;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use SplFileObject;
use function Ovos\services;

/**
 * Router
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Router
{
	/**
	 * @var string
	 */
	public const CACHE_ID_CONTROLLERS = 'controllers';

	/**
	 * The mode of the routing
	 *
	 * @var int
	 */
	protected $_mode;

	/**
	 * @var Request
	 */
	protected $_request;

	/**
	 * @var Url
	 */
	protected $_url;

	/**
	 * @param Request $request
	 */
	public function __construct(Request $request)
	{
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
		
		$this->_setRequest($request, $params);
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
		$controllers = $this->_getControllers(BASE_DIR . 'application' . DIRECTORY_SEPARATOR . 'controllers');
		
		// after checking for locale, check for controller (with optional namespace path), and action
		foreach($params as $key => $param)
		{
			// set controller
			foreach($controllers as $name => $children)
			{
				if(\is_string($name)
					&& isset($params[$key + 1])
					&& strcmp($name, $param) === 0
					&& \in_array($params[$key + 1], $children, true))
				{
					$controllers = $children;
					$controllerClass.= Strings::studlyCase($param) . '\\';
					$controller.= $param . '/';

					continue 2;
				}

				if(\is_string($children)
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
			$params = \array_slice($params, $key + 1);

			break;
		}

		// set action
		if(\count($params))
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
	 * @param string $dir
	 *
	 * @return array
	 */
	protected function _getControllers($dir): array
	{
		if(services()->cache && $pool = services()->cache->getPool())
		{
			if($pool->hasItem(self::CACHE_ID_CONTROLLERS))
			{
				return $pool->getItem(self::CACHE_ID_CONTROLLERS)->get();
			}
		}

		$controllers = Dir::getFiles($dir, function($file)
		{
			/**
			* @var SplFileObject $file
			*/
			$basename = $file->getBasename('.php');
			return Strings::snakeCase($basename);
		});

		if(services()->cache && $pool = services()->cache->getPool())
		{
			$item = $pool->getItem(self::CACHE_ID_CONTROLLERS)->set($controllers);
			$pool->save($item);
		}

		return $controllers;
	}
}