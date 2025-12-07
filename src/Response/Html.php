<?php
declare(strict_types=1);

namespace Ovos\Response;

use Ovos\Response;
use Override;

/**
 * Html
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Html extends Response
{
	protected ?string $response = null;
	
	public function __construct(
		?string $response = null,
	)
	{
		parent::__construct();
		
		$this->set($response);
		
		$this->setHeader('Content-Type',
			'text/html; charset=utf-8');
	}
	
	public function set(
		?string $response,
	): static
	{
		$this->response = $response;
		
		return $this;
	}
	
	public function append(
		string $response,
	): static
	{
		$this->response.= $response;
		
		return $this;
	}
	
	public function get(): ?string
	{
		return $this->response;
	}
	
	#[Override]
	public function __toString(): string
	{
		return (string)$this->response;
	}
}
