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

