<?php
declare(strict_types=1);

namespace Tests\Http;

use Ovos\Test;
use Ovos\Http\Body as HttpBody;

/**
 * Body
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Body extends Test
{
	public function decodesObject(): bool
	{
		return HttpBody::decode('{"a":1,"b":"x"}') === ['a' => 1, 'b' => 'x'];
	}
	
	public function decodesList(): bool
	{
		return HttpBody::decode('[1,2,3]') === [1, 2, 3];
	}
	
	public function nullOnEmpty(): bool
	{
		return HttpBody::decode('') === null
			&& HttpBody::decode(null) === null;
	}
	
	public function nullOnMalformed(): bool
	{
		return HttpBody::decode('{not json') === null;
	}
	
	public function nullOnNonArrayJson(): bool
	{
		return HttpBody::decode('"a string"') === null
			&& HttpBody::decode('42') === null;
	}
	
	public function idsSingle(): bool
	{
		return HttpBody::ids(['id' => 5]) === [5];
	}
	
	public function idsList(): bool
	{
		return HttpBody::ids(['ids' => [1, '2', 3]]) === [1, 2, 3];
	}
	
	public function idsDropsNonPositiveAndNonNumeric(): bool
	{
		return HttpBody::ids(['ids' => [1, 0, -3, 'x', 4]]) === [1, 4];
	}
	
	public function idsCaps(): bool
	{
		return HttpBody::ids(['ids' => [1, 2, 3, 4, 5]], 3) === [1, 2, 3];
	}
	
	public function idsEmptyWhenNeitherKeyPresent(): bool
	{
		return HttpBody::ids(['foo' => 'bar']) === [];
	}
	
	/**
	 * Regression: a blind (int) cast coerced arrays and true to 1, so a
	 * malformed bulk payload acted on record id 1 — one the request never
	 * named. Only real ints and digit strings may pass.
	 */
	public function idsRejectsNonScalarAndBooleanValues(): bool
	{
		return HttpBody::ids(['ids' => [[7], true, false, 3.5, null, '2x', '']]) === []
			&& HttpBody::ids(['id' => true]) === []
			&& HttpBody::ids(['id' => [7]]) === [];
	}
	
	public function idsKeepsValidValuesAmongJunk(): bool
	{
		return HttpBody::ids(['ids' => [true, 7, [1], '9', 'x']]) === [7, 9];
	}
}
