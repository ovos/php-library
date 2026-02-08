<?php
declare(strict_types=1);

namespace Ovos;

use function header;
use function http_response_code;
use function ob_get_length;

/**
 * Response
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Response
{
	protected Container $container;
	
	protected Application $app;
	
	/**
	 * HTTP code
	 */
	protected int $httpCode = 200;
	
	/**
	 * Sent
	 */
	protected bool $sent = false;
	
	/**
	 * Headers
	 */
	protected array $headers = [];
	
	/**
	 * Headers sent
	 */
	protected bool $headersSent = false;
	
	public function __construct()
	{
		$this->container = container();
		$this->app = $this->container->get(Application::class);
		$this->app->setResponse($this);
	}
	
	public function setHttpCode(
		int $httpCode,
	): static
	{
		$this->httpCode = $httpCode;
		
		return $this;
	}
	
	public function setHeader(
		string $name,
		mixed $value,
		bool $replace = false,
	): static
	{
		$this->headers[$name] = [
			'value' => $value,
			'replace' => $replace
		];
		
		return $this;
	}
	
	public function clearHeader(
		string $name,
	): static
	{
		if(isset($this->headers[$name]))
		{
			unset($this->headers[$name]);
		}
		
		return $this;
	}
	
	public function clearAllHeaders(): static
	{
		$this->headers = [];
		
		return $this;
	}
	
	/**
	 * Send headers
	 */
	public function sendHeaders(): static
	{
		if($this->headersSent())
		{
			return $this;
		}
		
		if($this->app->getRequest()->isCli() === false)
		{
			foreach($this->headers as $name => $header)
			{
				header(
					$name . ': ' . $header['value'],
					$header['replace'],
				);
			}
			
			http_response_code($this->httpCode);
		}
		
		$this->setHeadersSent(true);
		
		return $this;
	}
	
	public function send(): ?self
	{
		if($this->isSent())
		{
			return null;
		}
		
		if(ob_get_length() === 0) // nothing sent before
		{
			$this->sendHeaders();
		}
		
		echo $this;
		
		$this->setIsSent(true);
		
		return $this;
	}
	
	public function setIsSent(
		bool $sent,
	): static
	{
		$this->sent = $sent;
		
		return $this;
	}
	
	public function isSent(): bool
	{
		return $this->sent;
	}
	
	public function setHeadersSent(
		bool $headersSent,
	): static
	{
		$this->headersSent = $headersSent;
		
		return $this;
	}
	
	public function headersSent(): bool
	{
		return $this->headersSent;
	}
	
	public function __toString(): string
	{
		return '';
	}
}
