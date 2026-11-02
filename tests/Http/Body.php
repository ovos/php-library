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
	
}
