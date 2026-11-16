<?php
declare(strict_types=1);

namespace Tests\Controller;

use Ovos\Controller;
use Ovos\Exception\NotFoundException;
use Ovos\Response;
use Ovos\Test;
use ReflectionFunction;
use Throwable;
use TypeError;

use function in_array;
use function is_bool;
use function is_string;
use function str_contains;

/**
 * Controller::looseBoolParams — a word that reaches a bool parameter only
 * through PHP's weak coercion is refused, not read as true.
 *
 * dispatch() calls the action with invokeArgs(), which coerces weakly
 * whatever the controller file declares: `php cli.php maintenance
 * purge-events yse` gave the typo to `?bool $confirm`, which read it as
 * true and deleted every stored event. The router turns only yes, true,
 * no, false and null into real values.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class LooseBoolParams extends Test
{
	/** the names looseBoolParams() gives a closure's parameters for these arguments */
	protected function loose(
		callable $closure,
		array $arguments,
	): array
	{
		return Controller::looseBoolParams(new ReflectionFunction($closure), $arguments);
	}
	
	/**
	 * A controller that records how its action was called, and whose
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
			
			public ?bool $confirm = null;
			
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
			
			public function purge(
				?bool $confirm = null,
			): ?Response
			{
				$this->ran = true;
				$this->confirm = $confirm;
				
				return null;
			}
		};
	}
	
	/**
	 * RULE: a word given to a bool parameter, by position or by name, is
	 * named — a typo, another case, a word the router does not convert.
	 *
	 * Prevents: `purge-events yse`, `rescrub all NO` or `confirm off`
	 * confirming a destructive action.
	 */
	public function aWordForABoolIsNamed(): bool
	{
		$closure = fn(?bool $confirm = null) => null;
		
		return $this->loose($closure, ['yse']) === ['confirm']
			&& $this->loose($closure, ['NO']) === ['confirm']
			&& $this->loose($closure, ['false']) === ['confirm']
			&& $this->loose($closure, [' ']) === ['confirm']
			&& $this->loose($closure, ['confirm' => 'off']) === ['confirm']
			&& $this->loose(fn(bool $a, bool $b) => null, ['x', 'y']) === ['a', 'b'];
	}
	
	/**
	 * RULE: a real bool, null, and PHP's own spellings '', '0' and '1' pass.
	 *
	 * Prevents: a form's checkbox (value 1) or the router's own yes answering
	 * 404.
	 */
	public function phpsOwnSpellingsPass(): bool
	{
		$closure = fn(?bool $confirm = null) => null;
		foreach([true, false, null, '', '0', '1'] as $value)
		{
			if($this->loose($closure, [$value]) !== [])
			{
				return false;
			}
		}
		
		return $this->loose($closure, []) === [];
	}
	
	/**
	 * RULE: a type that takes the word as it is — a string, mixed, no type —
	 * passes it; a numeric string goes to a float, or to an int it fits, the
	 * type also admits; the literal type true refuses a string itself.
	 *
	 * Prevents: `bool|string $answer` (codesafe's learn edition) or
	 * `int|bool $page` given 5 being refused.
	 */
	public function aTypeThatTakesTheWordPassesIt(): bool
	{
		return $this->loose(fn(bool|string|null $answer = null) => null, ['maybe']) === []
			&& $this->loose(fn(mixed $case = null) => null, ['all']) === []
			&& $this->loose(fn($case = null) => null, ['all']) === []
			&& $this->loose(fn(int|bool $page) => null, ['5']) === []
			&& $this->loose(fn(int|bool $page) => null, ['5x']) === ['page']
			&& $this->loose(fn(int|bool $page) => null, ['99999999999999999999']) === ['page']
			&& $this->loose(fn(float|bool $ratio) => null, ['1.5']) === []
			&& $this->loose(fn(true $on) => null, ['yse']) === [];
	}
	
	/**
	 * RULE: an argument reaches the parameter PHP gives it — its position,
	 * its name, else a variadic's rest; one PHP gives to no parameter is
	 * nobody's.
	 *
	 * Prevents: `prune-issues all foo` checking the project's word against
	 * $confirm, or missing the word that lands on it.
	 */
	public function anArgumentIsCheckedWhereItLands(): bool
	{
		$closure = fn(?string $project = null, ?bool $confirm = null) => null;
		
		return $this->loose($closure, ['all', 'foo']) === ['confirm']
			&& $this->loose($closure, ['foo']) === []
			&& $this->loose($closure, ['all', 'confirm' => 'foo']) === ['confirm']
			&& $this->loose($closure, ['project' => 'foo']) === []
			&& $this->loose(fn(string $a, bool ...$flags) => null, ['x', '1', 'on']) === ['flags']
			&& $this->loose(fn(?bool $a = null) => null, ['1', 'extra']) === [];
	}
	
	/**
	 * RULE: the answer is PHP's own — a parameter is named exactly when
	 * invokeArgs() hands it a bool made from a string other than '', '0'
	 * and '1'.
	 *
	 * Prevents: the check refusing a word PHP passes as it is or turns into
	 * a number, or letting through one PHP turns into true.
	 */
	public function theAnswerAgreesWithPhp(): bool
	{
		$closures = [
			fn(?bool $c = null) => $c,
			fn(int|bool $c) => $c,
			fn(float|bool $c) => $c,
			fn(bool|string $c) => $c,
			fn(true $c) => $c,
			fn(mixed $c) => $c,
			fn($c) => $c,
			fn(int $c) => $c,
		];
		$values = ['yse', 'NO', '5', '5x', ' ', '0.0', '-3', '0x1A', '1e2',
			'9223372036854775807', '9223372036854775808', '99999999999999999999', '1e400', '', '0', '1'];
		
		foreach($closures as $closure)
		{
			foreach($values as $value)
			{
				try
				{
					$received = (new ReflectionFunction($closure))->invokeArgs([$value]);
				}
				catch(TypeError)
				{
					$received = null;
				}
				
				$phpCoerces = is_bool($received)
					&& is_string($value)
					&& in_array($value, ['', '0', '1'], true) === false;
				if(($this->loose($closure, [$value]) === ['c']) !== $phpCoerces)
				{
					return false;
				}
			}
		}
		
		return true;
	}
	
	/**
	 * RULE: dispatch() answers a word for a bool with NotFoundException,
	 * before the action runs, by position or by name; yes and '1' run it.
	 *
	 * Prevents: a typo after a destructive CLI action running it.
	 */
	public function dispatchRefusesAWordBeforeTheActionRuns(): bool
	{
		foreach([['yse'], ['confirm', 'yse']] as $arguments)
		{
			$refused = $this->controller();
			try
			{
				$refused->dispatch('purge', $arguments);
				
				return false;
			}
			catch(NotFoundException $exception)
			{
				if(str_contains($exception->getMessage(), '::purge() takes yes or no for $confirm') === false
					|| $refused->ran)
				{
					return false;
				}
			}
			catch(Throwable)
			{
				return false;
			}
		}
		
		$yes = $this->controller();
		$yes->dispatch('purge', [true]);
		$one = $this->controller();
		$one->dispatch('purge', ['1']);
		$none = $this->controller();
		$none->dispatch('purge');
		
		return $yes->ran && $yes->confirm === true
			&& $one->ran && $one->confirm === true
			&& $none->ran && $none->confirm === null;
	}
	
	/**
	 * RULE: a plugin that answers in preDispatch() still answers first.
	 *
	 * Prevents: the CLI-only 403 or a login redirect turning into a 404
	 * because the request also carried a word for a bool.
	 */
	public function aPluginsAnswerComesFirst(): bool
	{
		$controller = $this->controller(answeredByPlugin: true);
		try
		{
			return $controller->dispatch('purge', ['yse']) === null
				&& $controller->ran === false;
		}
		catch(Throwable)
		{
			return false;
		}
	}
}
