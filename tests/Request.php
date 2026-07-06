<?php
declare(strict_types=1);

namespace Tests;

use Ovos\Request as Subject;
use Ovos\Test;
use Ovos\Test\Internal;
use Override;

/**
 * Request
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Request extends Test
{
	protected ?string $originalMethod = null;
	
	public function __construct()
	{
		$this->originalMethod = $_SERVER['REQUEST_METHOD'] ?? null;
	}
	
	protected function withMethod(
		string $method,
	): Subject
	{
		$_SERVER['REQUEST_METHOD'] = $method;
		
		return new Subject;
	}
	
	public function getMethodIsUppercased(): bool
	{
		return $this->withMethod('patch')->getMethod() === 'PATCH'
			&& $this->withMethod('QUERY')->getMethod() === 'QUERY';
	}
	
	public function recognisesTheStandardMethods(): bool
	{
		return $this->withMethod('GET')->isGet() === true
			&& $this->withMethod('POST')->isPost() === true
			&& $this->withMethod('PUT')->isPut() === true
			&& $this->withMethod('DELETE')->isDelete() === true
			&& $this->withMethod('HEAD')->isHead() === true;
	}
	
	public function recognisesTheAddedMethods(): bool
	{
		return $this->withMethod('PATCH')->isPatch() === true
			&& $this->withMethod('OPTIONS')->isOptions() === true
			&& $this->withMethod('QUERY')->isQuery() === true;
	}
	
	public function isMethodIsCaseInsensitive(): bool
	{
		$request = $this->withMethod('patch');
		
		return $request->isPatch() === true
			&& $request->isMethod('PATCH') === true
			&& $request->isMethod(Subject::METHOD_PATCH) === true;
	}
	
	public function methodsDoNotCrossMatch(): bool
	{
		$query = $this->withMethod('QUERY');
		
		return $query->isQuery() === true
			&& $query->isGet() === false
			&& $query->isPost() === false;
	}
	
	/**
	 * Called by the runner after all test methods - restore $_SERVER
	 */
	#[Internal]
	#[Override]
	public function deconstruct(): void
	{
		if($this->originalMethod === null)
		{
			unset($_SERVER['REQUEST_METHOD']);
			
			return;
		}
		
		$_SERVER['REQUEST_METHOD'] = $this->originalMethod;
	}
}
