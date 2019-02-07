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
	 * @var int
	 */
	protected $_algorithm;

	/**
	 * @var array
	 */
	protected $_options;

	/**
	 */
	public function __construct()
	{
		if(PHP_VERSION_ID >= 70200)
		{
			$this->_algorithm = PASSWORD_ARGON2I;
			$this->_options = [
				'memory_cost' => PASSWORD_ARGON2_DEFAULT_MEMORY_COST * 2,
				'time_cost' => PASSWORD_ARGON2_DEFAULT_TIME_COST * 10,
				'threads' => PASSWORD_ARGON2_DEFAULT_THREADS * 1,
			];
			
			if(PHP_VERSION_ID >= 70300)
			{
				$this->_algorithm = PASSWORD_ARGON2ID;
			}
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
	 * @param int $algorithm
	 * 
	 * @return $this
	 */
	public function setAlgorithm(int $algorithm): self
	{
		$this->_algorithm = $algorithm;
		
		return $this;
	}

	/**
	 * @return int
	 */
	public function getAlgorithm(): int
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
	 * @param int $algorithm
	 * @param array $options
	 *
	 * @return bool|string
	 */
	public function hash(string $password, int $algorithm = null, $options = null)
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
	 * @param int $algorithm
	 * @param array $options
	 *
	 * @return bool
	 */
	public function needsRehash($password, int $algorithm = null, $options = null): bool
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
