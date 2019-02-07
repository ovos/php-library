<?php
declare(strict_types=1);

namespace Ovos;

/**
 * Response
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Response
{
	/**
	 * Application
	 *
	 * @var Application
	 */
	protected $_app;

	/**
	 * HTTP code
	 *
	 * @var int
	 */
	protected $_httpCode = 200;

	/**
	 * Headers
	 *
	 * @var array
	 */
	protected $_headers = [];

	/**
	 * Sent
	 *
	 * @var bool
	 */
	protected $_sent = false;

	/**
	 * Construct
	 */
	public function __construct()
	{
		$this->_app = app();
		$this->_app->setResponse($this);
	}

	/**
	 * @param int $httpCode
	 *
	 * @return $this
	 */
	public function setHttpCode(int $httpCode): self
	{
		$this->_httpCode = $httpCode;

		return $this;
	}

	/**
	 * @param string $name
	 * @param mixed $value
	 * @param bool $replace
	 *
	 * @return $this
	 */
	public function setHeader(string $name, $value, bool $replace = false): self
	{
		$this->_headers[$name] = [
			'value' => $value,
			'replace' => $replace
		];

		return $this;
	}

	/**
	 * @param string $name
	 *
	 * @return $this
	 */
	public function clearHeader($name): self
	{
		if(isset($this->_headers[$name]))
		{
			unset($this->_headers[$name]);
		}

		return $this;
	}

	/**
	 * @return $this
	 */
	public function clearAllHeaders(): self
	{
		$this->_headers = [];

		return $this;
	}

	/**
	 * Send headers
	 *
	 * @return $this
	 */
	public function sendHeaders(): self
	{
		if($this->_app->getRequest()->isCli() === false)
		{
			foreach($this->_headers as $name => $header)
			{
				header($name . ': ' . $header['value'], $header['replace']);
			}

			http_response_code($this->_httpCode);
		}

		return $this;
	}

	/**
	 * @return $this
	 */
	public function send(): self
	{
		if($this->isSent())
		{
			return null;
		}

		$this->sendHeaders();

		echo $this;

		$this->setIsSent(true);

		return $this;
	}

	/**
	 * @param bool $sent
	 *
	 * @return $this
	 */
	public function setIsSent(bool $sent): self
	{
		$this->_sent = $sent;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isSent(): bool
	{
		return $this->_sent;
	}

	/**
	 * @return string
	 */
	public function __toString(): string
	{
		return '';
	}
}
