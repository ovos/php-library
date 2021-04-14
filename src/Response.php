<?php
declare(strict_types=1);

namespace Ovos;

use function ob_get_level;
use function header;
use function http_response_code;

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
	protected Application $_app;

	/**
	 * HTTP code
	 *
	 * @var int
	 */
	protected int $_httpCode = 200;
	
	/**
	 * Sent
	 *
	 * @var bool
	 */
	protected bool $_sent = false;

	/**
	 * Headers
	 *
	 * @var array
	 */
	protected array $_headers = [];

	/**
	 * Headers sent
	 *
	 * @var bool
	 */
	protected bool $_headersSent = false;

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
	 * @return self
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
	 * @return self
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
	 * @return self
	 */
	public function clearHeader(string $name): self
	{
		if(isset($this->_headers[$name]))
		{
			unset($this->_headers[$name]);
		}

		return $this;
	}

	/**
	 * @return self
	 */
	public function clearAllHeaders(): self
	{
		$this->_headers = [];

		return $this;
	}

	/**
	 * Send headers
	 *
	 * @return self
	 */
	public function sendHeaders(): self
	{
		if($this->headersSent())
		{
			return $this;
		}
	
		if($this->_app->getRequest()->isCli() === false)
		{
			foreach($this->_headers as $name => $header)
			{
				header($name . ': ' . $header['value'], $header['replace']);
			}

			http_response_code($this->_httpCode);
		}
		
		$this->setHeadersSent(true);

		return $this;
	}

	/**
	 * @return null|$this
	 */
	public function send(): ?self
	{
		if($this->isSent())
		{
			return null;
		}

		if(ob_get_level() === 0) // nothing sent before
		{
			$this->sendHeaders();
		}

		echo $this;

		$this->setIsSent(true);

		return $this;
	}

	/**
	 * @param bool $sent
	 *
	 * @return self
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
	 * @param bool $headersSent
	 *
	 * @return self
	 */
	public function setHeadersSent(bool $headersSent): self
	{
		$this->_headersSent = $headersSent;

		return $this;
	}
	
	/**
	 * @return bool
	 */
	public function headersSent(): bool
	{
		return $this->_headersSent;
	}

	/**
	 * @return string
	 */
	public function __toString(): string
	{
		return '';
	}
}
