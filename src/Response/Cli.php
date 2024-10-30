<?php
declare(strict_types=1);

namespace Ovos\Response;

/**
 * Cli
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Cli extends Html
{
	/**
	 * @var bool
	 */
	protected bool $_coloredOutput = true;
	
	/**
	 * Construct
	 *
	 * @param ?string $response
	 */
	public function __construct(?string $response = null)
	{
		parent::__construct();
		
		$this->set($response);
	}
	
	/**
	 * @param bool $coloredOutput
	 * 
	 * @return self
	 */
	public function setColoredOutput(bool $coloredOutput): self
	{
		$this->_coloredOutput = $coloredOutput;
		
		return $this;
	}
	
	/**
	 * @return bool
	 */
	public function getColoredOutput(): bool
	{
		return $this->_coloredOutput;
	}
}
