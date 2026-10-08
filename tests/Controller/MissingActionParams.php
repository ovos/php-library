<?php
declare(strict_types=1);

namespace Tests\Controller;

use ArgumentCountError;
use Ovos\Controller;
use Ovos\Exception\NotFoundException;
use Ovos\Response;
use Ovos\Test;
use ReflectionFunction;
use Throwable;

use function array_map;
use function in_array;
use function str_contains;

/**
 * Controller::missingActionParams — a URL that names an action but leaves out
 * an argument the action requires is a 404, not a crash.
 *
 * dispatch() spread whatever the router found onto the action, so
 * `GET /user/unlock` (a scanner, without the id unlock() requires) died in
 * PHP's call with an ArgumentCountError and answered 500, reported to codesafe
 * as an application error (codesafe e628a7b51f2cac37, bo2go 2026-10-08).
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class MissingActionParams extends Test
{
	/** the names missingActionParams() gives a closure's parameters for these arguments */
	protected function missing(
		callable $closure,
		array $arguments,
	): array
	{
		return Controller::missingActionParams(new ReflectionFunction($closure), $arguments);
	}
	
	/**
	 * A controller that records whether its action ran, and whose
	 * preDispatch() can answer in the action's place, as a plugin does —
	 * without the container, the request or the configured plugins.
	 */
	protected function controller(
		bool $answeredByPlugin = false,
	): Controller
	{
		return new class($answeredByPlugin) extends Controller
		{
			public bool $ran = false;
			
			public function __construct(
				protected bool $answeredByPlugin,
			)
			{
			}
			
			public function preDispatch(
				array $actionParams,
			): void
			{
				$this->setDispatched($this->answeredByPlugin);
			}
			
			public function show(
				string $id,
				?string $tab = null,
			): ?Response
			{
				$this->ran = true;
				
				return null;
			}
		};
	}
	
	/**
	 * RULE: a required parameter left without a value is named, every one of
	 * them, in declaration order.
	 *
	 * Prevents: the 404's message naming the wrong argument, or only the first
	 * of several.
	 */
	public function aRequiredParameterWithoutAValueIsNamed(): bool
	{
		return $this->missing(fn(string $uniqueIid, ?string $code = null) => null, []) === ['uniqueIid']
			&& $this->missing(fn(string $a, int $b, ?string $c) => null, []) === ['a', 'b', 'c']
			&& $this->missing(fn(string $a, int $b, ?string $c) => null, ['x']) === ['b', 'c'];
	}
	
	/**
	 * RULE: a positional value fills the parameters from the first one, a
	 * named value the parameter of its name — the way getActionParams()
	 * builds the arguments and PHP spreads them.
	 *
	 * Prevents: a request that supplies the argument by name
	 * (/controller/action/id/5) being refused as if it had left it out.
	 */
	public function aPositionalOrANamedValueFillsIt(): bool
	{
		$closure = fn(string $id, string $code) => null;
		
		return $this->missing($closure, ['x']) === ['code']
			&& $this->missing($closure, ['code' => 'y']) === ['id']
			&& $this->missing($closure, ['x', 'code' => 'y']) === []
			&& $this->missing($closure, ['x', 'y']) === []
			&& $this->missing($closure, ['x', 'y', 'extra']) === [];
	}
	
	/**
	 * RULE: an optional parameter — a defaulted or a variadic one — is never
	 * named, and an action without parameters has none to miss.
	 *
	 * Prevents: an index action with `?int $page = null` answering 404 to the
	 * plain URL that leaves the page out.
	 */
	public function anOptionalParameterIsNeverNamed(): bool
	{
		return $this->missing(fn(?int $page = null, string $sort = 'name') => null, []) === []
			&& $this->missing(fn(string ...$rest) => null, []) === []
			&& $this->missing(fn(string $id, string ...$rest) => null, ['x']) === []
			&& $this->missing(fn() => null, []) === []
			&& $this->missing(fn() => null, ['extra']) === [];
	}
	
	/**
	 * RULE: the answer is PHP's own — no names exactly when PHP makes the
	 * call, some exactly when it throws ArgumentCountError.
	 *
	 * Prevents: the check refusing a call PHP would make, or letting through
	 * one it would crash on.
	 */
	public function theAnswerAgreesWithPhp(): bool
	{
		$closures = [
			fn(string $uniqueIid, ?string $code = null) => null,
			fn(string $id, string $code) => null,
			fn(?string $page = null) => null,
			fn(string $id, string ...$rest) => null,
			fn() => null,
		];
		$argumentSets = [
			[],
			['x'],
			['x', 'y'],
			['code' => 'y'],
			['x', 'code' => 'y'],
		];
		
		foreach($closures as $closure)
		{
			foreach($argumentSets as $arguments)
			{
				// a name the closure does not declare is an Error of its own,
				// one getActionParams() never produces
				$names = array_map(fn($parameter) => $parameter->getName(),
					(new ReflectionFunction($closure))->getParameters());
				if(isset($arguments['code']) && in_array('code', $names, true) === false)
				{
					continue;
				}
				
				try
				{
					$closure(...$arguments);
					$phpCalls = true;
				}
				catch(ArgumentCountError)
				{
					$phpCalls = false;
				}
				
				if(($this->missing($closure, $arguments) === []) !== $phpCalls)
				{
					return false;
				}
			}
		}
		
		return true;
	}
	
	/**
	 * RULE: dispatch() answers a missing argument with NotFoundException,
	 * before the action runs, and runs it when the argument is there.
	 *
	 * Prevents: `/user/unlock` without its id answering 500 again.
	 */
	public function dispatchAnswersAMissingArgumentWithNotFound(): bool
	{
		$refused = $this->controller();
		try
		{
			$refused->dispatch('show');
			
			return false;
		}
		catch(NotFoundException $exception)
		{
			if(str_contains($exception->getMessage(), '::show() requires $id,') === false
				|| $refused->ran)
			{
				return false;
			}
		}
		catch(Throwable)
		{
			return false;
		}
		
		$served = $this->controller();
		$served->dispatch('show', ['5']);
		
		return $served->ran;
	}
	
	/**
	 * RULE: a plugin that answers in preDispatch() still answers first — the
	 * check is made only for a call that is about to happen.
	 *
	 * Prevents: an anonymous visitor's login redirect, or the CLI-only 403,
	 * turning into a 404 because the URL also left out an argument.
	 */
	public function aPluginsAnswerComesFirst(): bool
	{
		$controller = $this->controller(answeredByPlugin: true);
		try
		{
			return $controller->dispatch('show') === null
				&& $controller->ran === false;
		}
		catch(Throwable)
		{
			return false;
		}
	}
}
