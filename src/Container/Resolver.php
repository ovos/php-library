<?php
declare(strict_types=1);

namespace Ovos\Container;

/**
 * Resolver
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Resolver
{
	/**
	 * @var array
	 */
	protected array $_parameters = [];
	
	/**
	 * @param array $_parameters
	 */
	public function __construct(array $_parameters = [])
	{
		$this->setParameters($_parameters);
	}
	
	/**
	 * @param array $parameters
	 *
	 * @return self
	 */
	public function setParameters(array $parameters = []): self
	{
		$this->_parameters = $parameters;
		
		return $this;
	}
	
	/**
	 * @return array
	 */
	public function getParameters(): array
	{
		return $this->_parameters;
	}
}
