<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Exception\NotFoundException;

/**
 * Dispatcher
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Dispatcher
{
	/**
	 * @param Request $request
	 *
	 * @return null|Response
	 *
	 * @throws NotFoundException
	 */
	public function dispatch(Request $request): ?Response
	{
		$controllerClass = $request->getControllerClass();
		if(!preg_match('~[[:alnum:]\\\?]+~i', $controllerClass)) // alnum + \
		{
			throw new NotFoundException('Invalid controller name.');
		}
		$controllerClassNs = 'Controllers\\' . $controllerClass;
		if(!class_exists($controllerClassNs))
		{
			throw new NotFoundException('Controller class does not exist "%s".', $controllerClassNs);
		}

		/** @var Controller $controller */
		$controller = new $controllerClassNs;
		if(!is_subclass_of($controller, 'Ovos\Controller'))
		{
			throw new NotFoundException('A controller has to extend a "Ovos\Controller" class.');
		}
		$controller->setParams($request->getParams());

		$action = $request->getActionMethod();
		if(!method_exists($controller, $action))
		{
			throw new NotFoundException('No method matching action name on controller "%s".', $controllerClass);
		}
		
		$result = $controller->dispatch($action, $request->getParams());
		if($result !== null && !($result instanceof Response))
		{
			return new Response\Html($result);
		}

		return null;
	}
}