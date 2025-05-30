<?php
declare(strict_types=1);

namespace Ovos\Container\Register;

use Ovos\Container\Register;
use Attribute;
use Closure;

/**
 * TypeClass
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class TypeClass extends Register
{
	/**
	 * @var ?Closure
	 */
	protected ?Closure $_initializer = null;
	
	/**
	 * @param array $parameters
	 * @param ?callable $initializer
	 */
	public function __construct(array $parameters = [],
		?callable $initializer = null,
	)
	{
		parent::__construct($parameters);
		
		$this->setInitializer($initializer);
	}
	
	/**
	 * @param ?callable $initializer
	 *
	 * @return self
	 */
	public function setInitializer(?callable $initializer): self
	{
		$this->_initializer = $initializer;
		
		return $this;
	}
	
	/**
	 * @return ?Closure
	 */
	public function getInitializer(): ?Closure
	{
		return $this->_initializer;
	}
}
