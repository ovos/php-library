<?php
declare(strict_types=1);

namespace Ovos\Response;

use Ovos\Response;
use Ovos\Exception;
use Override;
use stdClass;

use function Ovos\services;

use function json_encode;
use function property_exists;
use function is_string;
use	function count;

/**
 * JSON
 * Set GET variable "debug" to see formatted JSON on output
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 * @author Maciej Hołyszko <mh@ovos.at>
 */
class Json extends Response
{
	/**
	 * Object of data
	 *
	 * @var stdClass
	 */
	protected stdClass $_response;
	
	/**
	 * Options of json_encode
	 *
	 * @var int
	 */
	protected int $_options = 0;
	
	/**
	 */
	public function __construct()
	{
		parent::__construct();
		
		$this->_response = new stdClass;
		$this->_response->success = true;
		
		$this->setHeader('Content-Type', 'application/json; charset=utf-8');
	}
	
	/**
	 * Sets json_encode options
	 *
	 * @param int $value
	 * 
	 * @return static
	 */
	public function setOptions(int $value): static
	{
		$this->_options = $value;
		
		return $this;
	}
	
	/**
	 * Sets data
	 *
	 * @param string $name
	 * @param mixed $value
	 */
	public function __set(string $name, mixed $value): void
	{
		$this->_response->$name = $value;
	}
	
	/**
	 * Unsets data
	 *
	 * @param string $name
	 */
	public function __unset(string $name): void
	{
		unset($this->_response->$name);
	}
	
	/**
	 * Gets data
	 *
	 * @param string $name
	 *
	 * @return mixed
	 */
	public function &__get(string $name): mixed
	{
		if($this->__isset($name) === false)
		{
			$this->_response->$name = null;
		}
		
		return $this->_response->$name;
	}
	
	/**
	 * Checks if data exists
	 *
	 * @param string $name
	 *
	 * @return bool
	 */
	public function __isset(string $name): bool
	{
		return property_exists($this->_response, $name);
	}
	
	/**
	 * Sets response
	 *
	 * @param stdClass $response
	 *
	 * @return static
	 */
	public function set(stdClass $response): static
	{
		$this->_response = $response;
		
		return $this;
	}
	
	/**
	 * Mark response as success
	 * Not necessary to call this method, as the response is success by default
	 *
	 * @param ?string $message
	 *
	 * @return static
	 */
	public function success(?string $message = null): static
	{
		$this->clearErrors();
		$this->_response->success = true;
		
		if($message !== null)
		{
			$this->_response->message = $message;
		}
		
		return $this;
	}

	/**
	 * Mark response as failure
	 *
	 * @param null|string|Exception $exception
	 * @param bool $silent true = do not log this exception
	 *
	 * @return static
	 */
	public function failure(
		null|string|Exception $exception = null,
		bool $silent = false
	): static
	{
		$this->_response->success = false;
		if($exception !== null)
		{
			if($exception instanceof Exception)
			{
				$this->_response->error = $exception->getMessage();
			}
			else
			{
				$this->_response->error = $exception;
			}
			
			if($silent === false)
			{
				services()->events->log($exception);
			}
		}
		
		return $this;
	}
	
	/**
	 * Add an error message
	 *
	 * @param mixed $message
	 * @param null|mixed $key
	 *
	 * @return static
	 */
	public function error(mixed $message, mixed $key = null): static
	{
		if(!isset($this->_response->errors))
		{
			$this->_response->errors = [];
		}
		
		if($key !== null && is_string($key))
		{
			$this->_response->errors[$key] = $message;
		}
		else
		{
			$this->_response->errors[] = $message;
		}
		
		return $this;
	}
	
	/**
	 * Add error messages
	 *
	 * @param array $errors
	 *
	 * @return static
	 */
	public function errors(array $errors): static
	{
		foreach($errors as $key => $message)
		{
			$this->error($message, $key);
		}
		
		return $this;
	}
	
	/**
	 * Checks if there are errors in the current response
	 */
	public function hasErrors(): bool
	{
		return (
			(isset($this->_response->errors) && count($this->_response->errors))
			|| (isset($this->_response->error) && !empty($this->_response->error))
		);
	}
	
	/**
	 * Clear response errors
	 *
	 * @return static
	 */
	public function clearErrors(): static
	{
		unset($this->_response->errors, $this->_response->error);
		
		return $this;
	}
	
	/**
	 * @return string
	 */
	#[Override]
	public function __toString(): string
	{
		if($this->hasErrors())
		{
			$this->failure();
		}
		
		return (string)json_encode($this->_response, $this->_options);
	}
}
