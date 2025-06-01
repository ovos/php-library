<?php
declare(strict_types=1);

namespace Ovos\Container\Traits;

/**
 * TraitParameters
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
trait TraitParameters
{
	/**
	 * @var array
	 */
	protected array $_parameters = [];
	
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
