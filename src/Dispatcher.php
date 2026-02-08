<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Exception\NotFoundException;

use function class_exists;
use function method_exists;
use function preg_match;

/**
 * Dispatcher
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Dispatcher
{
	protected Container $container;
	
	public function __construct(
		Container $container,
	)
	{
		$this->container = $container;
	}
	
	public function dispatch(
		Request $request,
	): ?Response
	{
		$controllerClass = $request->getControllerClass();
		if(preg_match('~[[:alnum:]\\\?]+~i', $controllerClass) === false) // alnum + \
		{
			throw new NotFoundException(
				'Invalid controller name.');
		}
		$controllerClassNs = Controller::NAMESPACE . $controllerClass;
		if(class_exists($controllerClassNs) === false)
		{
			throw new NotFoundException(
				'Controller class does not exist "%s".', $controllerClassNs);
		}
		
		/** @var Controller $controller */
		$controller = $this->container
			->getClass($controllerClassNs, $controllerClassNs);
		if($controller instanceof Controller === false)
		{
			throw new NotFoundException(
				'A controller has to extend a "Ovos\Controller" class.');
		}
		$controller->setParams($request->getParams());
		
		$action = $request->getActionMethod();
		if(method_exists($controller, $action) === false)
		{
			throw new NotFoundException(
				'No method matching action name on controller "%s".', $controllerClass);
		}
		
		return $controller->dispatch($action, $request->getParams());
	}
}
