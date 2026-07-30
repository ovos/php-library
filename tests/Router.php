<?php
declare(strict_types=1);

namespace Tests;

use Ovos\Test;
use Ovos\Controller;

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
	 * action on every CLI controller. The exclusion covers the whole Ovos\
	 * namespace now: no framework class contributes actions.
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

