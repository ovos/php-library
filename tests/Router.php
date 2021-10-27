<?php
declare(strict_types=1);

namespace Tests;

use Ovos\Test;
use Ovos\Controller;

/**
 * Url
 *
 * @package Tests
 * @author Marcin Gil <mg@ovos.at>
 */
class Router extends Test
{
	public function parameters()
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
	
	public function parametersNamed()
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
	
	public function parametersNamedSkipOptional()
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
	
	public function parametersMixed()
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
}


class RouterTestController extends Controller
{
	public bool $firstParam;
	public bool $secondParam;

	public function test(
		bool $firstParam = false,
		bool $secondParam = true): void
	{
		$this->firstParam = $firstParam;
		$this->secondParam = $secondParam;
	}
}

