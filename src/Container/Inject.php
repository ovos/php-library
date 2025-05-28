<?php
declare(strict_types=1);

namespace Ovos\Container;

use Attribute;

/**
 * Inject
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Inject
{
	/**
	 * @var string 
	 */
	protected string $_key;
	
	/**
	* @param string $key
	*/
	public function __construct(string $key)
	{
		$this->_key = $key;
	}
	
	/**
	* @return string
	*/
	public function getKey(): string
	{
		return $this->_key;
	}
}
