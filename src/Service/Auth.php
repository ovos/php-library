<?php
declare(strict_types=1);

namespace Ovos\Service;

use Models\UserLogin;
use Ovos\Exception;
use Ovos\ArrayObject;
use Ovos\Invoker;
use Ovos\Service;
use Ovos\Service\Auth\Result;
use Ovos\Service\Session;
use Ovos\Service\Cookies;
use Ovos\Strings;
use Ovos\Url;
use Models\User;
use Models\UserToken;
use Stores\Users;
use Stores\UsersTokens;
use Stores\UsersLogins;
use Eety\Client;
use DateTime;
use DateInterval;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;
use function Ovos\services;

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
	public const SYMBOL = 'auth';

	/**
	 * @var string
	 */
	public const TOKEN_COOKIE_NAME = 'user_token';

	/**#@+
	 * Result constants (bitmask)
	 */
	public const RESULT_SUCCESS = 1;
	public const RESULT_ERROR = 2;
	public const RESULT_INVALID_USERNAME = 4;
	public const RESULT_INVALID_PASSWORD = 8;
	public const RESULT_NO_TOKEN = 16;
	public const RESULT_INVALID_TOKEN = 32;
	public const RESULT_ATTEMPTS_LIMIT_REACHED = 64;
	public const RESULT_API_ERROR = 128;
	/**#@-*/

	/**
	 * @var string
	 */
	public const SESSION_NAMESPACE = 'auth';

	/**
	 * @var User
	 */
	protected $_user;

	/**
	 * @var Session
	 */
	protected $_sessionService;

	/**
	 * @var Cookies
	 */
	protected $_cookiesService;

	/**
	 * @var ArrayObject
	 */
	protected $_session;

	/**
	 * @var array
	 */
	protected $_dependsOn = ['session', 'cookies'];

	/**
	 */
	public function __construct()
	{
		parent::__construct();

		$this->_cookiesService = services()->cookies;
		$this->_sessionService = services()->session;

		$this->_initSession();
		$this->_restore();
	}

	/**
	 * @return string
	 */
	public function getSymbol(): string
	{
		return self::SYMBOL;
	}

	/**
	 */
	protected function _initSession(): void
	{
		$this->_session = $this->_sessionService->{self::SESSION_NAMESPACE};
		if($this->_session === null)
		{
			$this->_session = new ArrayObject;
			$this->_sessionService->{self::SESSION_NAMESPACE} = $this->_session;
		}
	}

	/**
	 * @return $this
	 */
	public function clearSession(): self
	{
		unset($this->_sessionService->{self::SESSION_NAMESPACE});

		return $this;
	}

	/**
	 * @param string $username
	 * @param string $password
	 * @param bool $remember
	 *
	 * @return Result
	 */
	public function authenticate($username, $password, $remember = false): Result
	{
		$store = new Users;
		$user = $store->getByUsername($username);

		if($user === false)
		{
			return $this->saveLogin(
				(new Result(self::RESULT_ERROR | self::RESULT_INVALID_USERNAME))
					->setUsername($username));
		}
		
		// verify is user is not locked out on the API
		try
		{
			if($endpoint = services()->mvno->getEndpointHierarchy($username))
			{
				// update endpoint and account id
				$user->endpoint_id = $endpoint->id;
				$user->account_id = $endpoint->account->id;
			}
		}
		catch(RequestException $exception)
		{
			// write to events logger
			if(services()->events)
			{
				services()->events->log($exception);
			}
			
			return $this->saveLogin(
				(new Result(self::RESULT_ERROR | self::RESULT_API_ERROR))
				->setUsername($user->username));
		}

		// check if this username did not have too many tries already
		$attempts = $this->_app->getConfig()->system->user->authentication->attempts;
		if($attempts->check)
		{
			$logins = new UsersLogins;
			if($logins->countUsernameResultsWithin(
				$user->username,
				self::RESULT_ERROR,
				$attempts->minutes) >= $attempts->limit)
			{
				return $this->saveLogin(
					(new Result(self::RESULT_ERROR | self::RESULT_ATTEMPTS_LIMIT_REACHED))
					->setUsername($user->username));
			}
		}

		// if a temporary password exists and is correct, use it as primary password
		if($user->password_temporary !== null
			&& password_verify($password, $user->password_temporary))
		{
			$user->password = $user->password_temporary;
		}
		
		if($this->_verifyPassword($password, $user) === false)
		{
			return $this->saveLogin(
				new Result(self::RESULT_ERROR | self::RESULT_INVALID_PASSWORD, $user));
		}

		// if temporary password exists, null it (successful login)
		if($user->password_temporary !== null)
		{
			$user->password_temporary = null;
		}
		$user->save(); // saves only if something was modified
		$this->_user = $user;
		$this->_user->updateLogin();

		if($remember || Client::isApp())
		{
			$this->saveUserToken($user);
		}

		$this->persist();

		return $this->saveLogin(
			new Result(self::RESULT_SUCCESS, $user));
	}

	/**
	 * @param string $password
	 * @param User $user
	 * 
	 * @return bool
	 */
	protected function _verifyPassword(string $password, User $user): bool
	{
		$valid = password_verify($password, $user->password);
		if($valid === true)
		{
			return true;
		}
		
		if($user->password_alternate !== null 
			&& password_verify($password, $user->password_alternate))
		{
			return true;
		}
		
		return false;
	}

	/**
	 * @return Result
	 */
	public function authenticateWithToken(): Result
	{
		if($this->hasUser())
		{
			return new Result(self::RESULT_SUCCESS, $this->getUser());
		}

		if(($userToken = $this->_cookiesService->get(self::TOKEN_COOKIE_NAME)) === null)
		{
			return new Result(self::RESULT_ERROR | self::RESULT_NO_TOKEN);
		}

		// expire before we find an expired one ;-)
		Invoker::invokeWithChance(function()
		{
			$this->expireTokens();
		}, 10);

		$tokens = new UsersTokens;
		$token = $tokens->get($userToken);
		if($token === false)
		{
			return new Result(self::RESULT_ERROR | self::RESULT_INVALID_TOKEN);
		}

		$users = new Users;
		$user = $users->get($token->user_id);
		if($user === false)
		{
			return $this->saveLogin(
				new Result(self::RESULT_ERROR | self::RESULT_INVALID_TOKEN),
				UserLogin::SOURCE_TOKEN);
		}

		// set the user and persist it
		$this->_user = $user;
		$this->persist(true);

		return $this->saveLogin(
			new Result(self::RESULT_SUCCESS, $user),
			UserLogin::SOURCE_TOKEN);
	}

	/**
	 * @param Result $result
	 * @param string $source
	 *
	 * @return Result
	 */
	public function saveLogin(Result $result, $source = UserLogin::SOURCE_LOGIN): Result
	{
		$login = new UserLogin;
		$login->username = $result->getUsername();
		if($user = $result->getUser())
		{
			$login->user_id = $user->id;
		}
		$login->source = $source;
		$login->result = $result->getResult();
		$login->insert();

		return $result;
	}


	/**
	 * @return int number of expires tokens
	 */
	public function expireTokens(): int
	{
		$tokens = new UsersTokens;

		return $tokens->expire();
	}

	/**
	 * @param User $user
	 *
	 * @return self
	 */
	public function saveUserToken($user): self
	{
		$token = new UserToken;
		$token->user_id = $user->id;
		$token->token = Strings::random(32);

		$expiresAt = new DateTime;
		$expiresAt->add(DateInterval::createFromDateString($this->_app->getConfig()->system->user->remember_period));
		$token->expires_at = $expiresAt->format('Y-m-d H:i:s');

		if($token->insertUnique('token', function($attempt, $lastValue)
		{
			return Strings::random(32);
		}, 10))
		{
			$this->_cookiesService->set(
				self::TOKEN_COOKIE_NAME,
				$token->token,
				$expiresAt->getTimestamp(),
				SYSTEM_DIR,
				'',
				false,
				true
			);
		}

		return $this;
	}

	/**
	 * @return bool
	 */
	public function deleteUserToken(): bool
	{
		if(($userToken = $this->_cookiesService->get(self::TOKEN_COOKIE_NAME)) === null)
		{
			return true;
		}

		$this->_cookiesService->unset(self::TOKEN_COOKIE_NAME);

		$tokens = new UsersTokens;
		$token = $tokens->get($userToken);
		if($token === false)
		{
			return true;
		}

		if($token->delete())
		{
			return true;
		}

		return false;
	}

	/**
	 * @return bool
	 */
	public function hasUser(): bool
	{
		return $this->_user !== null;
	}

	/**
	 * @return null|User
	 */
	public function getUser(): ?User
	{
		return $this->_user;
	}

	/**
	 * @param Url $returnUrl
	 *
	 * @return $this
	 */
	public function setReturnUrl($returnUrl): self
	{
		$this->_session->return_url = $returnUrl;

		return $this;
	}

	/**
	 * @return Url|null
	 */
	public function getReturnUrl(): ?Url
	{
		if($this->_session->offsetExists('return_url') === false)
		{
			return null;
		}

		return $this->_session->return_url;
	}

	/**
	 * @param bool $regenerateId
	 *
	 * @return self
	 */
	public function persist($regenerateId = false): self
	{
		if($regenerateId)
		{
			$this->_sessionService->regenerateId();
		}

		$this->_session->user = $this->_user->export();

		return $this;
	}

	/**
	 * @return bool
	 */
	protected function _restore(): bool
	{
		if($this->_session->offsetExists('user'))
		{
			$this->_user = User::import($this->_session->user);

			return true;
		}

		return false;
	}

	/**
	 * @return $this
	 */
	public function logout(): self
	{
		$this->_user = null;
		$this->deleteUserToken();
		$this->clearSession();

		return $this;
	}
}
