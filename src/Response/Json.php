<?php
declare(strict_types=1);

namespace Ovos\Response;

use Ovos\Response;
use Ovos\Exception;
use Override;
use Ovos\Service\Events;
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
	 */
	protected stdClass $response;
	
	/**
	 * Options of json_encode
	 */
	protected int $options = JSON_THROW_ON_ERROR;
	
	/**
	 */
	public function __construct()
	{
		parent::__construct();
		
		$this->response = new stdClass;
		$this->response->success = true;
		
		$this->setHeader('Content-Type',
			'application/json; charset=utf-8');
	}
	
	/**
	 * Sets json_encode options
	 */
	public function setOptions(
		int $value,
	): static
	{
		$this->options = $value;
		
		return $this;
	}
	
	/**
	 * Sets data
	 */
	public function __set(
		string $name,
		mixed $value,
	): void
	{
		$this->response->$name = $value;
	}
	
	/**
	 * Unsets data
	 */
	public function __unset(
		string $name,
	): void
	{
		unset($this->response->$name);
	}
	
	/**
	 * Gets data
	 */
	public function &__get(
		string $name,
	): mixed
	{
		if($this->__isset($name) === false)
		{
			$this->response->$name = null;
		}
		
		return $this->response->$name;
	}
	
	/**
	 * Checks if data exists
	 */
	public function __isset(
		string $name,
	): bool
	{
		return property_exists($this->response, $name);
	}
	
	/**
	 * Sets response
	 */
	public function set(
		stdClass $response,
	): static
	{
		$this->response = $response;
		
		return $this;
	}
	
	/**
	 * Mark response as success
	 * Not necessary to call this method, as the response is success by default
	 */
	public function success(
		?string $message = null,
	): static
	{
		$this->clearErrors();
		$this->response->success = true;
		
		if($message !== null)
		{
			$this->response->message = $message;
		}
		
		return $this;
	}

	/**
	 * Mark response as failure
	 */
	public function failure(
		null|string|Exception $exception = null,
		bool $silent = false, // true = do not log this exception
	): static
	{
		$this->response->success = false;
		if($exception !== null)
		{
			if($exception instanceof Exception)
			{
				$this->response->error = $exception->getMessage();
			}
			else
			{
				$this->response->error = $exception;
			}
			
			if($silent === false)
			{
				$this->container->get(Events::SYMBOL)
					->log($exception);
			}
		}
		
		return $this;
	}
	
	/**
	 * Add an error message
	 */
	public function error(
		mixed $message,
		mixed $key = null,
	): static
	{
		if(isset($this->response->errors) === false)
		{
			$this->response->errors = [];
		}
		
		if(is_string($key))
		{
			$this->response->errors[$key] = $message;
		}
		else
		{
			$this->response->errors[] = $message;
		}
		
		return $this;
	}
	
	/**
	 * Add error messages
	 */
	public function errors(
		array $errors,
	): static
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
			(isset($this->response->errors) && count($this->response->errors))
			|| (isset($this->response->error) && !empty($this->response->error))
		);
	}
	
	/**
	 * Clear response errors
	 */
	public function clearErrors(): static
	{
		unset($this->response->errors, $this->response->error);
		
		return $this;
	}
	
	#[Override]
	public function __toString(): string
	{
		if($this->hasErrors())
		{
			$this->failure();
		}
		
		return (string)json_encode($this->response, $this->options);
	}
}
