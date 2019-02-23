<?php
declare(strict_types=1);

namespace Ovos\Response;

use Ovos\Response;
use Ovos\Exception;
use stdClass;
use function Ovos\app;
use function Ovos\services;

/**
 * Json
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
	protected $_response;

	/**
	 * Options of json_encode
	 *
	 * @var int
	 */
	protected $_options = 0;

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

	 * @return $this
	 */
	public function setOptions(int $value): self
	{
		$this->_options = $value;

		return $this;
	}

	/**
	 * Sets data
	 *
	 * @param string $name
	 * @param mixed $value

	 * @return $this
	 */
	public function __set(string $name, $value): self
	{
		$this->_response->$name = $value;

		return $this;
	}

	/**
	 * Unsets data
	 *
	 * @param string $name
	 *
	 * @return $this
	 */
	public function __unset(string $name): self
	{
		unset($this->_response->$name);

		return $this;
	}

	/**
	 * Gets data
	 *
	 * @param string $name
	 *
	 * @return mixed
	 */
	public function &__get(string $name)
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
	 * @return $this
	 */
	public function set(stdClass $response): self
	{
		$this->_response = $response;

		return $this;
	}

	/**
	 * Mark response as success
	 * Not necessary to call this method, as the response is success by default
	 *
	 * @param string $message
	 *
	 * @return $this
	 */
	public function success(string $message = null): self
	{
		$this->clearErrors();
		$this->_response->success = true;

		if ($message !== null)
		{
			$this->_response->message = $message;
		}

		return $this;
	}

	/**
	 * Mark response as failure
	 *
	 * @param Exception|string $exception
	 * @param bool $silent true = do not log this exception
	 *
	 * @return $this
	 */
	public function failure($exception = null, bool $silent = false): self
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

			if(!$silent)
			{
				services()->events->log($exception);
			}
		}

		return $this;
	}

	/**
	 * Add error message
	 *
	 * @param mixed $message
	 * @param mixed $key
	 *
	 * @return $this
	 */
	public function error($message, $key = null): self
	{
		if(!isset($this->_response->errors))
		{
			$this->_response->errors = [];
		}

		if($key !== null && \is_string($key))
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
	 * @return $this
	 */
	public function errors(array $errors): self
	{
		foreach($errors as $key => $message)
		{
			$this->error($message, $key);
		}

		return $this;
	}

	/**
	 * Checks if there are errors in current response
	 */
	public function hasErrors(): bool
	{
		return ((isset($this->_response->errors) && \count($this->_response->errors))
			|| (isset($this->_response->error) && !empty($this->_response->error)));
	}

	/**
	 * Clear response errors
	 *
	 * @return $this
	 */
	public function clearErrors(): self
	{
		unset($this->_response->errors, $this->_response->error);

		return $this;
	}

	/**
	 * @return string
	 */
	public function __toString(): string
	{
		if($this->hasErrors())
		{
			$this->failure();
		}

		return (string)json_encode($this->_response, $this->_options);
	}
}
