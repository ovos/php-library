<?php
declare(strict_types=1);

namespace Ovos\Service\Auth;

use Ovos\Exception;
use Ovos\Invoker;
use Ovos\Service;
use Ovos\Service\Session;
use Ovos\Service\Cookies;
use Ovos\Strings;
use Ovos\Url;
use Models\User;
use Models\UserToken;
use Stores\Users;
use Stores\UsersTokens;
use function Ovos\services;
use ArrayObject;
use DateTime;
use DateInterval;
use RuntimeException;

/**
 * Result
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Result
{
	/**
	 * @var int
	 */
	protected $_result;

	/**
	 * @var User
	 */
	protected $_user;

	/**
	 * @var string
	 */
	protected $_username;

	/**
	 * @param int $result
	 * @param User $user
	 */
	public function __construct(int $result, User $user = null)
	{
		$this->setResult($result);
		$this->setUser($user);

		if($user instanceof User)
		{
			$this->setUsername($user->username);
		}
	}

	/**
	 * @param int $result
	 *
	 * @return $this
	 */
	public function setResult(int $result): self
	{
		$this->_result = $result;

		return $this;
	}

	/**
	 * @return int
	 */
	public function getResult(): int
	{
		return $this->_result;
	}

	/**
	 * @param User|null $user
	 *
	 * @return $this
	 */
	public function setUser(?User $user): self
	{
		$this->_user = $user;

		return $this;
	}

	/**
	 * @return User|null
	 */
	public function getUser(): ?User
	{
		return $this->_user;
	}

	/**
	 * @param string $username
	 *
	 * @return $this
	 */
	public function setUsername(?string $username): self
	{
		$this->_username = $username;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getUsername(): ?string
	{
		return $this->_username;
	}
}
