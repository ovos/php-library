<?php
declare(strict_types=1);

namespace Tests;

use Ovos\ArrayObject;
use Ovos\Controller;
use Ovos\Exception\NotFoundException;
use Ovos\Request;
use Ovos\Router as OvosRouter;
use Ovos\Test;
use Ovos\Url;

use function class_alias;
use function class_exists;
use function str_contains;

/**
 * Url
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Router extends Test
{
	public function parameters(): bool
	{
		$firstParam = true;
		$secondParam = false;
		
		$controller = new RouterTestController;
		$controller->dispatch('test', [
			$firstParam, $secondParam
		]);
		
		return $firstParam === $controller->firstParam
			&& $secondParam === $controller->secondParam;
	}
	
	public function parametersNamed(): bool
	{
		$firstParam = true;
		$secondParam = false;
		
		$controller = new RouterTestController;
		$controller->dispatch('test', [
			'firstParam', $firstParam,
			'secondParam', $secondParam
		]);
		
		return $firstParam === $controller->firstParam
			&& $secondParam === $controller->secondParam;
	}
	
	public function parametersNamedSkipOptional(): bool
	{
		$firstParam = false;
		$secondParam = false;
		
		$controller = new RouterTestController;
		$controller->dispatch('test', [
			'secondParam', $secondParam
		]);
		
		return $firstParam === $controller->firstParam
			&& $secondParam === $controller->secondParam;
	}
	
	public function parametersMixed(): bool
	{
		$firstParam = true;
		$secondParam = false;
		
		$controller = new RouterTestController;
		$controller->dispatch('test', [
			$firstParam, 'secondParam', $secondParam
		]);
		
		return $firstParam === $controller->firstParam
			&& $secondParam === $controller->secondParam;
	}
	/**
	 * Which method names a URL segment may dispatch to.
	 *
	 * method_exists() was the whole test, and it answers true for protected
	 * methods — which the dispatcher then calls successfully, because the call
	 * site is inside Controller, an ancestor. Every protected helper on every
	 * controller was a reachable endpoint.
	 */
	public function protectedMethodsAreNotActions(): bool
	{
		return Controller::resolveActionMethod(RouterTestController::class, 'test')
				=== 'test'
			&& Controller::resolveActionMethod(RouterTestController::class, 'helper')
				=== null
			&& Controller::resolveActionMethod(RouterTestController::class, 'nope')
				=== null;
	}
	
	/**
	 * dispatch() is public, so /<controller>/dispatch/<action> re-entered the
	 * dispatcher and ran <action> while the request still reported "dispatch"
	 * — the string authorization plugins look up, which made every list-based
	 * admin or demo guard bypassable. Nothing declared on the base class is an
	 * action.
	 */
	public function frameworkPlumbingIsNotAnAction(): bool
	{
		foreach(['dispatch', 'preDispatch', 'setParams', 'setDispatched',
			'getActionParams', 'addPlugin'] as $method)
		{
			if(Controller::resolveActionMethod(RouterTestController::class, $method) !== null)
			{
				return false;
			}
		}
		
		return true;
	}
	
	/**
	 * PHP calls methods case-insensitively while guard maps compare strings,
	 * so /errors/deletE reached delete() past a map listing "delete". The
	 * resolver hands back the DECLARED spelling, so the rest of the stack sees
	 * one name.
	 */
	public function actionNameIsCanonicalised(): bool
	{
		return Controller::resolveActionMethod(RouterTestController::class, 'TEST')
				=== 'test'
			&& Controller::resolveActionMethod(RouterTestController::class, 'tESt')
				=== 'test';
	}
	
	/**
	 * Controller\Cli both DECLARES public plumbing of its own (log, getPid,
	 * the http-access surface) and OVERRIDES preDispatch — which used to move
	 * the hook's declaring class off the base and quietly re-expose it as an
	 * action on every CLI controller. The exclusion covers the whole
	 * Controller family now: no framework controller base contributes
	 * actions.
	 */
	public function frameworkDeclaredPlumbingNeverResolves(): bool
	{
		foreach(['preDispatch', 'log', 'getPid', 'setAllowHttpAccess',
			'getMemoryUsageMb', 'usesColor'] as $method)
		{
			if(Controller::resolveActionMethod(RouterTestCliController::class, $method) !== null)
			{
				return false;
			}
		}
		
		// the controller's own actions still resolve — the rule refuses the
		// framework's surface, not the class
		return Controller::resolveActionMethod(RouterTestCliController::class, 'work')
			=== 'work';
	}
	
	/**
	 * The hooks are refused by NAME, whoever declares them — an application
	 * override is dispatch machinery all the same. Compared on the reflected
	 * name (the declared spelling), so a lowercase probe cannot slip past.
	 */
	public function hooksNeverResolveWhoeverDeclaresThem(): bool
	{
		return Controller::resolveActionMethod(RouterTestHookController::class, 'preDispatch')
				=== null
			&& Controller::resolveActionMethod(RouterTestHookController::class, 'predispatch')
				=== null
			&& Controller::resolveActionMethod(RouterTestHookController::class, 'test')
				=== 'test';
	}
	
	/**
	 * A web path whose first segment is no controller and no action of the
	 * default controller is a page the application does not have: 404 naming
	 * the path, never the default action with the segments dropped
	 */
	public function anUnknownFirstSegmentIsNotFoundOnTheWeb(): bool
	{
		try
		{
			$this->routed($this->request(false, ['proc', 'self', 'environ']));
		}
		catch(NotFoundException $exception)
		{
			// the default controller exists: the 404 is this branch's, not the missing class's
			return str_contains($exception->getMessage(), 'No controller for')
				&& str_contains($exception->getMessage(), 'proc/self/environ')
				&& class_exists('Controllers\RouterFallbackIndex', false);
		}
		
		return false;
	}
	
	/** a controller directory named alone is no page either */
	public function aBareControllerDirectoryIsNotFoundOnTheWeb(): bool
	{
		try
		{
			$this->routed($this->request(false, ['router_fallback_admin']));
		}
		catch(NotFoundException)
		{
			return true;
		}
		
		return false;
	}
	
	/** a command line keeps the default action for a word it does not know */
	public function anUnknownFirstSegmentRunsTheDefaultActionOnTheCli(): bool
	{
		return $this->routed($this->request(true, ['proc', 'self'])) === ['proc', 'self'];
	}
	
	/** a numeric first segment is an argument of the default action */
	public function aNumericFirstSegmentStaysWithTheDefaultAction(): bool
	{
		return $this->routed($this->request(false, ['123', 'x'])) === ['123', 'x'];
	}
	
	/** a named controller takes the segments after it (a slug) */
	public function aNamedControllerKeepsItsFreeSegments(): bool
	{
		$request = $this->request(false, ['router_fallback_news', 'my-slug']);
		
		return $this->routed($request) === ['my-slug']
			&& $request->getControllerClass() === 'RouterFallbackNews';
	}
	
	/** the default controller named by its segment takes the segments after it too */
	public function aNamedDefaultControllerKeepsItsFreeSegments(): bool
	{
		return $this->routed($this->request(false, ['router_fallback_index', 'x'])) === ['x'];
	}
	
	/** a controller inside a directory takes the segments after it */
	public function aNestedControllerKeepsItsFreeSegments(): bool
	{
		$request = $this->request(false, ['router_fallback_admin', 'router_fallback_users', 'x']);
		
		return $this->routed($request) === ['x']
			&& $request->getControllerClass() === 'RouterFallbackAdmin\RouterFallbackUsers';
	}
	
	/** an action of the default controller resolves, its segments after it */
	public function anActionOfTheDefaultControllerResolves(): bool
	{
		$request = $this->request(false, ['contact', 'x']);
		
		return $this->routed($request) === ['x']
			&& $request->getActionMethod() === 'contact';
	}
	
	/** a web or a command line request for these segments, RouterFallbackIndex its default controller */
	protected function request(
		bool $cli,
		array $segments,
	): RouterFallbackRequest
	{
		$request = new RouterFallbackRequest($cli, $segments);
		$request->setController('router_fallback_index');
		$request->setControllerClass('RouterFallbackIndex');
		
		return $request;
	}
	
	/** the params Router::getParams() leaves for the action, read off the request's URL as route() does */
	protected function routed(
		RouterFallbackRequest $request,
	): array
	{
		// the router looks a controller up as Controllers\<Name>
		foreach([
			RouterFallbackIndex::class => 'Controllers\RouterFallbackIndex',
			RouterFallbackNews::class => 'Controllers\RouterFallbackNews',
			RouterFallbackUsers::class => 'Controllers\RouterFallbackAdmin\RouterFallbackUsers',
		] as $class => $alias)
		{
			if(class_exists($alias, false) === false)
			{
				class_alias($class, $alias);
			}
		}
		
		return (new RouterFallbackRouter($this->app, $request))
			->params($request, $request->getUrl()->getComponents());
	}
}

/** the router with a fixed controller list: no cache, no controllers directory */
class RouterFallbackRouter extends OvosRouter
{
	public function params(
		Request $request,
		array $params,
	): array
	{
		return $this->getParams($request, $params);
	}
	
	protected function getControllers(
		ArrayObject $modules,
	): array
	{
		// a directory is a key holding its controllers, as Dir::getTree() lists one
		return [
			'router_fallback_news',
			'router_fallback_index',
			'router_fallback_admin' => ['router_fallback_users'],
		];
	}
}

/** a web or a command line request as the test says, not as PHP_SAPI, for the given segments */
class RouterFallbackRequest extends Request
{
	public function __construct(
		protected bool $cli,
		array $segments,
	)
	{
		parent::__construct();
		
		$this->url = (new Url)->setComponents($segments);
	}
	
	public function isCli(): bool
	{
		return $this->cli;
	}
}

class RouterFallbackIndex extends Controller
{
	public function index(): void
	{
	}
	
	public function contact(): void
	{
	}
}

class RouterFallbackNews extends Controller
{
	public function index(): void
	{
	}
}

class RouterFallbackUsers extends Controller
{
	public function index(): void
	{
	}
}

class RouterTestController extends Controller
{
	public bool $firstParam;
	public bool $secondParam;
	
	public function test(
		bool $firstParam = false,
		bool $secondParam = true,
	): void
	{
		$this->firstParam = $firstParam;
		$this->secondParam = $secondParam;
	}
	
	/** not an action — reachable as one before resolveActionMethod() */
	protected function helper(): void
	{
	}
}

class RouterTestCliController extends Controller\Cli
{
	public function work(): void
	{
	}
}

class RouterTestHookController extends Controller
{
	/** an application-level hook override — still never an action */
	public function preDispatch(
		array $actionParams,
	): void
	{
	}
	
	public function test(): void
	{
	}
}

