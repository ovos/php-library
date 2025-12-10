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
	public const string SYMBOL = 'auth';
	
	protected ?User $user = null;
	
	public function setUser(?User $user): static
	{
		$this->user = $user;
		
		return $this;
	}
	
	public function hasUser(): bool
	{
		return $this->user !== null;
	}
	
	public function getUser(): ?User
	{
		return $this->user;
	}
}
