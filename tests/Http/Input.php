<?php
declare(strict_types=1);

namespace Tests\Http;

use Ovos\Http\Input as Subject;
use Ovos\Test;

/**
 * Input
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Input extends Test
{
	protected function input(): Subject
	{
		return new Subject([
			'search' => '  hello  ',
			'page' => '2',
			'limit' => 50,
			'ratio' => '3.5',
			'checked' => 'yes',
			'active' => false,
			'sort' => ['field' => 'ts', 'dir' => 'desc'],
			'ids' => [1, '2', 0, -3, 'x', true],
			'to' => ['a@example.com', ' b@example.com '],
			'nothing' => null,
			'blob' => ['a' => 1],
		]);
	}
	
	public function stringReadsScalarsElseDefault(): bool
	{
		$in = $this->input();
		
		return $in->string('search') === '  hello  '
			&& $in->trimmed('search') === 'hello'
			&& $in->string('limit') === '50'      // int coerced
			&& $in->string('blob', 'x') === 'x'   // array → default
			&& $in->string('missing', 'd') === 'd';
	}
	
	public function intOnlyAcceptsCleanIntegers(): bool
	{
		$in = $this->input();
		
		return $in->int('page') === 2          // digit string
			&& $in->int('limit') === 50        // real int
			&& $in->int('search', 7) === 7     // "hello" is not an int
			&& $in->int('blob', 7) === 7       // array never casts to 1
			&& $in->int('missing', 9) === 9;
	}
	
	public function floatCoercesNumbers(): bool
	{
		$in = $this->input();
		
		return $in->float('ratio') === 3.5
			&& $in->float('limit') === 50.0
			&& $in->float('search', 1.0) === 1.0;
	}
	
	public function boolUsesFrameworkSemantics(): bool
	{
		$in = $this->input();
		
		return $in->bool('checked') === true    // "yes"
			&& $in->bool('active') === false    // real false
			&& $in->bool('missing', true) === true
			&& $in->bool('search', false) === false; // unknown string → default
	}
	
	public function dotPathsReachNested(): bool
	{
		$in = $this->input();
		
		return $in->string('sort.field') === 'ts'
			&& $in->string('sort.dir') === 'desc'
			&& $in->string('sort.missing', 'x') === 'x'
			&& $in->has('sort.field') === true
			&& $in->has('sort.nope') === false
			&& $in->has('nothing') === true; // present but null
	}
	
	public function listAndArray(): bool
	{
		$in = $this->input();
		
		return $in->array('sort') === ['field' => 'ts', 'dir' => 'desc']
			&& $in->list('ids') === [1, '2', 0, -3, 'x', true]
			&& $in->list('sort') === []    // a map is not a list
			&& $in->list('missing') === [];
	}
	
	public function idsAreCleanPositiveInts(): bool
	{
		$in = $this->input();
		
		// 1 and "2" qualify; 0, -3, "x", true are dropped
		return $in->ids() === [1, 2];
	}
	
	public function emailsValidateOrNull(): bool
	{
		$in = $this->input();
		$bad = new Subject(['to' => ['a@example.com', 'not-an-email']]);
		
		return $in->emails('to') === ['a@example.com', 'b@example.com']
			&& $in->email('to.0') === 'a@example.com'
			&& $bad->emails('to') === null      // one invalid → whole payload rejected
			&& $in->emails('missing') === [];
	}
	
	public function emptyInput(): bool
	{
		$empty = new Subject;
		
		return $empty->isEmpty() === true
			&& $empty->string('x', 'd') === 'd'
			&& $empty->ids() === []
			&& $this->input()->isEmpty() === false;
	}
}
