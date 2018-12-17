<?php
declare(strict_types=1);

namespace Ovos\Form\Filter;

use Ovos\Form\Filter;

/**
 * StripPrefix
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class StripPrefix extends Filter
{
	/**
	 * @var string
	 */
	protected $_prefix;

	/**
	 * @param string $prefix
	 *
	 * @return $this
	 */
	public function setPrefix(?string $prefix): self
	{
		$this->_prefix = $prefix;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getPrefix(): ?string
	{
		return $this->_prefix;
	}

	/**
	 * @param string $prefix
	 */
	public function __construct(?string $prefix)
	{
		$this->setPrefix($prefix);
	}

	/**
	 * @param null|mixed $value
	 *
	 * @return mixed
	 */
	public function filter($value)
	{
		if($this->_prefix === null)
		{
			return $value;
		}

		if(strpos($value, $this->_prefix) !== 0)
		{
			return $value;
		}

		return substr($value, \strlen($this->_prefix));
	}
}
