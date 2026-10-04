<?php
declare(strict_types=1);

namespace Tests\Store;

use Ovos\ArrayObject;
use Ovos\Connection\Mysql as MysqlConnection;
use Ovos\Container\Inject;
use Ovos\Exception\UnavailableException;
use Ovos\Model\Mysql as Model;
use Ovos\Store\Mysql as Store;
use Ovos\Test;
use Ovos\Test\Internal;
use Override;
use Throwable;

/**
 * A database that cannot be reached is UnavailableException, everywhere —
 * never "no rows". A store used to answer null from getSource(), statement()
 * then null and fetchModels() an empty list, so an outage read as an empty
 * table (codesafe cached that as "no projects" and refused every sender's
 * key for five minutes after MySQL was back). A failed connect is also
 * remembered for `retry_after` seconds, so an outage costs one connect
 * timeout per request, not one per query.
 *
 * The unreachable server is 127.0.0.1:1 — nothing listens there, the connect
 * is refused at once.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Unavailable extends Test
{
	public const string CONNECTION = 'tests_unreachable';

	#[Inject('config')]
	protected ArrayObject $config;

	public function __construct()
	{
		$this->config->connections->offsetSet(self::CONNECTION, new ArrayObject(self::unreachable(60)));
	}
	
	/**
	 * RULE: a store's source, and every read through it, throws
	 * UnavailableException when the database cannot be reached.
	 *
	 * Prevents: an outage answered as an empty result.
	 */
	public function aStoreThrowsUnavailableInsteadOfNoRows(): bool
	{
		$store = new class() extends Store
		{
			public const ?string TABLE = 'tests';
			
			protected string $sourceName = Unavailable::CONNECTION;
		};
		
		return self::throwsUnavailable(static fn() => $store->getSource())
			&& self::throwsUnavailable(static fn() => $store->executeFind());
	}
	
	/**
	 * RULE: a model's source throws UnavailableException too — it used to be
	 * a TypeError out of source(): PDO.
	 */
	public function aModelThrowsUnavailable(): bool
	{
		$model = new class() extends Model
		{
			protected string $sourceName = Unavailable::CONNECTION;

			public static function getStoreClass(): string
			{
				return Store::class;
			}
		};
		
		return self::throwsUnavailable(static fn() => $model->getSource());
	}
	
	/**
	 * RULE: a connect that failed is not tried again inside `retry_after`,
	 * and is tried again once it has passed.
	 *
	 * Prevents: a 5-second connect timeout paid by every query of a request.
	 */
	public function aFailedConnectIsRememberedForTheWindow(): bool
	{
		$remembered = self::counting(60);
		$remembered->getClient();
		$remembered->getClient();
		$remembered->getClient();
		
		$retried = self::counting(0);
		$retried->getClient();
		$retried->getClient();
		
		return $remembered->attempts === 1
			&& $retried->attempts === 2;
	}
	
	/**
	 * RULE: requireClient() names the connection in its message and throws
	 * the typed exception; getClient() stays null for the callers that
	 * degrade on their own.
	 */
	public function requireClientThrowsWhereGetClientIsNull(): bool
	{
		$connection = self::counting(60);
		
		try
		{
			$connection->requireClient();
		}
		catch(UnavailableException $exception)
		{
			return $connection->getClient() === null
				&& $exception->getMessage() === 'The connection to "tests_unreachable" is unavailable.';
		}
		
		return false;
	}
	
	#[Internal]
	#[Override]
	public function deconstruct(): void
	{
		$this->config->connections->offsetUnset(self::CONNECTION);
	}
	
	/**
	 * @return array<string, int|string>
	 */
	protected static function unreachable(
		int $retryAfter,
	): array
	{
		return [
			'type' => 'mysql',
			'host' => '127.0.0.1',
			'port' => 1,
			'database' => 'tests_unreachable',
			'username' => 'nobody',
			'password' => '',
			'connect_timeout' => 1,
			'retry_after' => $retryAfter,
		];
	}
	
	/**
	 * An unreachable MySQL connection that counts its connect attempts
	 */
	protected static function counting(
		int $retryAfter,
	): MysqlConnection
	{
		return new class(new ArrayObject(self::unreachable($retryAfter))) extends MysqlConnection
		{
			public int $attempts = 0;
			
			#[Override]
			public function connect(): bool
			{
				$this->attempts++;
				
				return parent::connect();
			}
		};
	}
	
	protected static function throwsUnavailable(
		callable $call,
	): bool
	{
		try
		{
			$call();
		}
		catch(UnavailableException)
		{
			return true;
		}
		catch(Throwable)
		{
			return false;
		}
		
		return false;
	}
}
