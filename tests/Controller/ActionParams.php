<?php
// Weak calls below provide PHP's expected argument conversion for comparison.
declare(strict_types=0);

namespace Tests\Controller;

use Ovos\Controller;
use Ovos\Test;
use Throwable;

class ActionParams extends Test
{
	public function dispatchUsesNativeCoercion(): bool
	{
		foreach(['integer', 'decimal', 'text', 'flag', 'number', 'integerOrFlag', 'integerOrFalse', 'integerOrText', 'nullable', 'variadic'] as $action)
		{
			foreach(['1e2', '99999999999999999999', '42', '2.0', 'word', '', true, false, 0, null, []] as $value)
			{
				$native = new ArgumentController;
				$dispatched = new ArgumentController;
				$expectedError = $actualError = null;
				try { $native->$action($value); }
				catch(Throwable $error) { $expectedError = $error::class; }
				try { $dispatched->dispatch($action, [$value]); }
				catch(Throwable $error) { $actualError = $error::class; }
				if($expectedError !== $actualError || $native->received !== $dispatched->received)
				{
					return false;
				}
			}
		}
		return true;
	}
	
	public function preservesNamedNullAndVariadicArguments(): bool
	{
		$controller = new ArgumentController;
		$controller->dispatch('pair', ['first', null, 'second', '5']);
		if($controller->received !== [null, 5])
		{
			return false;
		}
		$controller->dispatch('variadic', ['1', '2', '3']);
		return $controller->received === [1, 2, 3];
	}
	
	public function pluginsRunBeforeArgumentConversion(): bool
	{
		$controller = new ArgumentController;
		$controller->stop = true;
		$controller->dispatch('integer', ['invalid']);
		return $controller->received === [] && $controller->before === ['invalid'];
	}
}

class ArgumentController extends Controller
{
	public array $received = [];
	public array $before = [];
	public bool $stop = false;
	
	public function __construct() {}
	public function preDispatch(array $actionParams): void
	{
		$this->before = $actionParams;
		$this->setDispatched($this->stop);
	}
	public function postDispatch(): void {}
	public function integer(int $value): void { $this->received = [$value]; }
	public function decimal(float $value): void { $this->received = [$value]; }
	public function text(string $value): void { $this->received = [$value]; }
	public function flag(bool $value): void { $this->received = [$value]; }
	public function number(int|float $value): void { $this->received = [$value]; }
	public function integerOrFlag(int|bool $value): void { $this->received = [$value]; }
	public function integerOrFalse(int|false $value): void { $this->received = [$value]; }
	public function integerOrText(int|string $value): void { $this->received = [$value]; }
	public function nullable(?string $value): void { $this->received = [$value]; }
	public function pair(?string $first, int $second): void { $this->received = [$first, $second]; }
	public function variadic(int ...$values): void { $this->received = $values; }
}
