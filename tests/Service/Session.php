<?php
declare(strict_types=1);

namespace Tests\Service;

use Ovos\ArrayObject;
use Ovos\Exception;
use Ovos\Service\Session as SessionService;
use Ovos\Test;
use Ovos\Test\Internal;
use Override;

use function ini_get;
use function ini_set;
use function str_contains;

/**
 * Session — how the "session.ini" block of the php handler reaches php.ini
 */
class Session extends Test
{
	/**
	 * The phpredis keys the tests set, with values none of them has by
	 * default, so a key that is never written cannot pass by accident
	 *
	 * @var array<string, string>
	 */
	protected const array REDIS_KEYS = [
		'locking_enabled' => '1',
		'lock_expire' => '61',
		'lock_wait_time' => '50001',
		'lock_retries' => '-1',
	];
	
	/**
	 * The redis.session.* values before each test, put back afterwards
	 *
	 * @var array<string, string|false>
	 */
	protected array $saved = [];
	
	#[Internal]
	#[Override]
	public function prepare(): void
	{
		foreach(self::REDIS_KEYS as $key => $value)
		{
			$this->saved[$key] = ini_get('redis.session.' . $key);
			
			// a start value that differs from the one the test expects
			ini_set('redis.session.' . $key, '7');
		}
	}
	
	#[Internal]
	#[Override]
	public function finalize(): void
	{
		foreach($this->saved as $key => $value)
		{
			if($value !== false)
			{
				ini_set('redis.session.' . $key, $value);
			}
		}
	}
	
	/**
	 * RULE: the phpredis keys of the ini block (locking_enabled,
	 * lock_expire, ...) set the redis.session.* directives the phpredis
	 * save handler reads.
	 * Falsify: prefix every key with "session." — ini_set() then names no
	 * directive and returns false, and locking stays at phpredis's default
	 * (off).
	 */
	public function phpredisKeysReachTheSaveHandler(): bool
	{
		$this->session(SessionService::HANDLER_PHP, self::REDIS_KEYS);
		
		foreach(self::REDIS_KEYS as $key => $value)
		{
			if(ini_get('redis.session.' . $key) !== $value)
			{
				return false;
			}
		}
		
		return true;
	}
	
	/**
	 * RULE: a key PHP's session module knows goes to session.<key>, a key
	 * only phpredis knows goes to redis.session.<key>.
	 * Falsify: drop either candidate prefix — the key it served is then
	 * refused as unknown.
	 */
	public function keysResolveToTheModuleThatHasThem(): bool
	{
		$session = new class extends SessionService
		{
			public function __construct()
			{
			}
			
			public function directive(
				string $key,
			): string
			{
				return $this->iniDirective($key);
			}
		};
		
		return $session->directive('gc_maxlifetime') === 'session.gc_maxlifetime'
			&& $session->directive('save_handler') === 'session.save_handler'
			&& $session->directive('locking_enabled') === 'redis.session.locking_enabled'
			&& $session->directive('compression') === 'redis.session.compression';
	}
	
	/**
	 * RULE: a key that is no directive of either module stops the
	 * construction, naming the key.
	 * Falsify: return the session.<key> name for an unknown key instead of
	 * throwing — ini_set() ignores it and nothing is reported.
	 */
	public function anUnknownKeyIsRefused(): bool
	{
		try
		{
			$this->session(SessionService::HANDLER_PHP, ['lockingenabled' => '1']);
		}
		catch(Exception $exception)
		{
			return str_contains($exception->getMessage(), '"lockingenabled"');
		}
		
		return false;
	}
	
	/**
	 * RULE: the json handler does not apply the ini block at all, so a key
	 * there is never checked.
	 * Falsify: apply the block whatever the handler — the unknown key then
	 * throws.
	 */
	public function theJsonHandlerLeavesTheBlockAlone(): bool
	{
		$this->session(SessionService::HANDLER_JSON, ['lockingenabled' => '1']);
		
		return true;
	}
	
	/**
	 * A Session service over a config with the given handler and ini block
	 *
	 * @param array<string, string> $ini
	 */
	protected function session(
		string $handler,
		array $ini,
	): SessionService
	{
		return new SessionService(new ArrayObject([
			'cookies' => ['prefix' => null],
			'session' => [
				'handler' => $handler,
				'cookie_name' => 'session',
				'ini' => $ini,
			],
		]));
	}
}
