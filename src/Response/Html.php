<?php
declare(strict_types=1);

namespace Ovos\Response;

use Ovos\Response;

/**
 * Html
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Html extends Response
{
	/**
	 * @var ?string
	 */
	protected ?string $_response = null;

	/**
	 * Construct
	 *
	 * @param string $response
	 */
	public function __construct(string $response = null)
	{
		parent::__construct();

		$this->set($response);

		$this->setHeader('Content-Type', 'text/html; charset=utf-8');
	}

	/**
	 * @param ?string $response
	 *
	 * @return self
	 */
	public function set(?string $response): self
	{
		$this->_response = $response;

		return $this;
	}
	
	/**
	 * @param string $response
	 *
	 * @return self
	 */
	public function append(string $response): self
	{
		$this->_response.= $response;

		return $this;
	}

	/**
	 * @return ?string
	 */
	public function get(): ?string
	{
		return $this->_response;
	}

	/**
	 * @return string
	 */
	public function __toString(): string
	{
		return (string)$this->_response;
	}
}
