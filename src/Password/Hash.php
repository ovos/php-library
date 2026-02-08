<?php
declare(strict_types=1);

namespace Ovos\Password;

/**
 * Hash
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Hash
{
	protected string $algorithm;
	
	protected array $options;
	
	public function __construct()
	{
		if(defined('PASSWORD_ARGON2ID'))
		{
			$this->algorithm = PASSWORD_ARGON2ID;
			$this->options = [
				'memory_cost' => PASSWORD_ARGON2_DEFAULT_MEMORY_COST * 2,
				'time_cost' => PASSWORD_ARGON2_DEFAULT_TIME_COST * 10,
				'threads' => PASSWORD_ARGON2_DEFAULT_THREADS * 1,
			];
		}
		else
		{
			$this->algorithm = PASSWORD_BCRYPT;
			$this->options = [
				'cost' => 10,
			];
		}
	}
	
	public function setAlgorithm(
		string $algorithm,
	): static
	{
		$this->algorithm = $algorithm;
		
		return $this;
	}
	
	public function getAlgorithm(): string
	{
		return $this->algorithm;
	}
	
	public function setOptions(
		array $options,
	): static
	{
		$this->options = $options;
		
		return $this;
	}
	
	public function getOptions(): array
	{
		return $this->options;
	}
	
	public function hash(
		string $password,
		?string $algorithm = null,
		?array $options = null,
	): null|bool|string
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
	
	public function needsRehash(
		string $password,
		?string $algorithm = null,
		?array $options = null,
	): bool
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
