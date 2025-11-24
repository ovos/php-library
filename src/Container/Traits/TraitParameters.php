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
	 * @return static
	 */
	public function setParameters(array $parameters = []): static
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
