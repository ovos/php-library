<?php
declare(strict_types=1);

namespace Tests\Controller;

use Countable;
use Ovos\Controller;
use Ovos\Test;
use ReflectionFunction;
use ReflectionType;
use Stringable;

/**
 * Controller::castParam — a request value cast for an action's parameter.
 *
 * A CLI or URL argument arrives as a string, or as the true / false / null
 * the router makes of yes|true, no|false and null. A single int, float or
 * bool parameter has always been cast; anything else was handed over as it
 * is — and a union type crashed the dispatch outright, because only a
 * single named type has getName() (ovos/codesafe `rename run yes`,
 * 2026-09-25: `yes` became true, the ?string argument refused it, and the
 * union type that should have taken it could not be read).
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class ActionParams extends Test
{
	/** the parameter type of a one-argument closure */
	protected function type(
		callable $closure,
	): ?ReflectionType
	{
		return (new ReflectionFunction($closure))->getParameters()[0]->getType();
	}
	
	/**
	 * RULE: a single type casts exactly as before — int, float, bool cast,
	 * string and untyped handed over, the router's true left alone for them.
	 *
	 * Prevents: support for unions changing what every existing action gets.
	 */
	public function aSingleTypeCastsAsItAlwaysDid(): bool
	{
		return Controller::castParam($this->type(fn(int $x) => null), '42') === 42
			&& Controller::castParam($this->type(fn(?int $x) => null), '7') === 7
			&& Controller::castParam($this->type(fn(float $x) => null), '1.5') === 1.5
			&& Controller::castParam($this->type(fn(bool $x) => null), '1') === true
			&& Controller::castParam($this->type(fn(string $x) => null), 'abc') === 'abc'
			&& Controller::castParam($this->type(fn(?string $x) => null), true) === true
			&& Controller::castParam($this->type(fn(mixed $x) => null), true) === true
			&& Controller::castParam(null, 'raw') === 'raw';
	}
	
	/**
	 * RULE: a value whose type the union holds stays as it is.
	 *
	 * Prevents: `yes` for a string|bool parameter reaching the action as "1",
	 * or a word for int|string being forced into 0.
	 */
	public function aValueTheUnionHoldsStaysAsItIs(): bool
	{
		return Controller::castParam($this->type(fn(string|bool|null $x) => null), true) === true
			&& Controller::castParam($this->type(fn(string|bool|null $x) => null), false) === false
			&& Controller::castParam($this->type(fn(string|bool|null $x) => null), 'overwrite') === 'overwrite'
			&& Controller::castParam($this->type(fn(string|bool|null $x) => null), null) === null
			&& Controller::castParam($this->type(fn(int|string $x) => null), 'abc') === 'abc'
			&& Controller::castParam($this->type(fn(int|string $x) => null), '42') === '42'
			&& Controller::castParam($this->type(fn(string|true $x) => null), true) === true;
	}
	
	/**
	 * RULE: a value the union does not hold is coerced in PHP's own order —
	 * int (an integer string, a bool), float (a numeric string), string,
	 * bool — into the first member it can become.
	 *
	 * Prevents: an int|float action getting the string "3", and a bool into
	 * int|string becoming anything but PHP's own int(1) — every expectation
	 * here is what PHP itself does when the same value is passed.
	 */
	public function anotherValueIsCoercedInPhpsOrder(): bool
	{
		return Controller::castParam($this->type(fn(int|float $x) => null), '3') === 3
			&& Controller::castParam($this->type(fn(int|float $x) => null), '2.5') === 2.5
			&& Controller::castParam($this->type(fn(int|float $x) => null), ' 12 ') === 12
			&& Controller::castParam($this->type(fn(int|bool $x) => null), '5') === 5
			&& Controller::castParam($this->type(fn(int|bool $x) => null), 'abc') === true
			&& Controller::castParam($this->type(fn(float|bool $x) => null), true) === true
			&& Controller::castParam($this->type(fn(int|float $x) => null), true) === 1
			&& Controller::castParam($this->type(fn(int|string $x) => null), true) === 1
			&& Controller::castParam($this->type(fn(int|string $x) => null), false) === 0
			&& Controller::castParam($this->type(fn(float|string $x) => null), false) === 0.0;
	}
	
	/**
	 * RULE: a type there is nothing to cast to — an intersection, a class —
	 * leaves the value alone instead of failing the dispatch.
	 *
	 * Prevents: the Error "Call to undefined method getName()" that took the
	 * whole action down for a type the caster cannot read.
	 */
	public function aTypeItCannotReadLeavesTheValueAlone(): bool
	{
		return Controller::castParam($this->type(fn(Countable&Stringable $x) => null), 'abc') === 'abc'
			&& Controller::castParam($this->type(fn(Stringable|int $x) => null), '9') === 9
			&& Controller::castParam($this->type(fn(Stringable $x) => null), 'abc') === 'abc';
	}
}
