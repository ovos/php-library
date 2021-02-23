<?php
declare(strict_types=1);

namespace Ovos\Password;

/**
 * Hash
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Hash
{
	/**
	 * @var string
	 */
	protected string $_algorithm;

	/**
	 * @var array
	 */
	protected array $_options;

	/**
	 */
	public function __construct()
	{
		if(defined('PASSWORD_ARGON2ID'))
		{
			$this->_algorithm = PASSWORD_ARGON2ID;
			$this->_options = [
				'memory_cost' => PASSWORD_ARGON2_DEFAULT_MEMORY_COST * 2,
				'time_cost' => PASSWORD_ARGON2_DEFAULT_TIME_COST * 10,
				'threads' => PASSWORD_ARGON2_DEFAULT_THREADS * 1,
			];
		}
		else
		{
			$this->_algorithm = PASSWORD_BCRYPT;
			$this->_options = [
				'cost' => 10,
			];
		}
	}

	/**
	 * @param string $algorithm
	 * 
	 * @return $this
	 */
	public function setAlgorithm($algorithm): self
	{
		$this->_algorithm = $algorithm;
		
		return $this;
	}

	/**
	 * @return string
	 */
	public function getAlgorithm()
	{
		return $this->_algorithm;
	}

	/**
	 * @param array $options
	 * 
	 * @return $this
	 */
	public function setOptions(array $options): self
	{
		$this->_options = $options;
		
		return $this;
	}

	/**
	 * @return array
	 */
	public function getOptions(): array
	{
		return $this->_options;
	}
	
	/**
	 * @param string $password
	 * @param string $algorithm
	 * @param array $options
	 *
	 * @return bool|string
	 */
	public function hash(string $password, string $algorithm = null,
		array $options = null)
	{
		if($algorithm !== null)
		{
			$this->setAlgorithm($algorithm);
		}
		if($options !== null)
		{
			$this->setOptions($options);
		}
	
		return password_hash($password, $this->getAlgorithm(), $this->getOptions());
	}
	
	/**
	 * @param string $password
	 * @param string $algorithm
	 * @param array $options
	 *
	 * @return bool
	 */
	public function needsRehash(string $password, string $algorithm = null,
		array $options = null): bool
	{
		if($algorithm !== null)
		{
			$this->setAlgorithm($algorithm);
		}
		if($options !== null)
		{
			$this->setOptions($options);
		}
	
		return password_needs_rehash($password, $this->getAlgorithm(), $this->getOptions());
	}
}
