<?php
declare(strict_types=1);

namespace Ovos\Service;

use Models\User;
use Ovos\Service;

/**
 * Auth
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Auth extends Service
{
	/**
	 * @var string
	 */
	public const string SYMBOL = 'auth';
	
	/**
	 * @var ?User
	 */
	protected ?User $_user = null;
	
	/**
	 * @param ?User $user
	 *
	 * @return self
	 */
	public function setUser(?User $user): self
	{
		$this->_user = $user;
		
		return $this;
	}
	
	/**
	 * @return bool
	 */
	public function hasUser(): bool
	{
		return $this->_user !== null;
	}
	
	/**
	 * @return ?User
	 */
	public function getUser(): ?User
	{
		return $this->_user;
	}
}
