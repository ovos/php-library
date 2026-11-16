<?php
declare(strict_types=1);

namespace Tests\Connection;

use Ovos\ArrayObject;
use Ovos\Connection\Redis as Subject;
use Ovos\Connections;
use Ovos\Test;
use Redis as RedisClient;
use RedisException;

use function bin2hex;
use function microtime;
use function preg_match;
use function random_bytes;

/**
 * Redis
 *
 * A connection the server dropped (a failover, a restart of the server, an
 * idle timeout) must not leave a long-running process with a dead client for
 * good: ext-redis marks the socket failed and never opens it again by itself
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Redis extends Test
{
	/**
	 * The server kills this very connection: with phpredis' retries (MAX_RETRIES)
	 * the call that finds out reconnects inside itself, on the same database
	 * and with the same options, and answers
	 */
	public function aConnectionTheServerDroppedIsBridgedInsideTheCall(): bool
	{
		$config = clone $this->container
			->getClass(Connections::class)
			->get('redis')
			->getConfig();
		$config->database = 2;
		$connection = new Subject($config);
		$client = $connection->getClient();
		$key = 'reconnect_' . bin2hex(random_bytes(4));
		
		if($client instanceof RedisClient === false)
		{
			return false;
		}
		
		$timeout = $client->getOption(RedisClient::OPT_READ_TIMEOUT);
		$client->set($key, 'kept', ['ex' => 60]);
		static::killConnectionOf($client, $connection);
		
		try
		{
			$read = $client->get($key);
			
			return $read === 'kept'
				&& $client->getDbNum() === 2
				&& $client->getOption(RedisClient::OPT_READ_TIMEOUT) === $timeout
				&& $connection->getClient() === $client;
		}
		finally
		{
			$client->del($key);
		}
	}
	
	/**
	 * Without retries the call that finds the connection gone fails, and the
	 * socket stays failed: the next getClient() hands out a new, working client
	 * instead of the dead one
	 */
	public function aClientTheServerDroppedIsReplacedByANewOne(): bool
	{
		$connection = new class(
			$this->container
				->getClass(Connections::class)
				->get('redis')
				->getConfig(),
		) extends Subject
		{
			protected const int MAX_RETRIES = 0;
		};
		$client = $connection->getClient();
		
		if($client instanceof RedisClient === false)
		{
			return false;
		}
		
		static::killConnectionOf($client, $connection);
		
		try
		{
			$client->ping();
			
			return false;
		}
		catch(RedisException)
		{
			// the call that finds the connection gone fails, as it must
		}
		
		$next = $connection->getClient();
		
		return $next instanceof RedisClient
			&& $next !== $client
			&& $next->ping() !== false;
	}
	
	/**
	 * A server that does not answer costs one connect timeout, not one per
	 * call: the calls inside `retry_after` return at once
	 */
	public function aServerThatStaysDownCostsOneConnectPerWindow(): bool
	{
		$connection = new Subject(new ArrayObject([
			// TEST-NET-1 (RFC 5737): nothing ever answers there
			'host' => '192.0.2.1',
			'port' => 6379,
			'database' => 0,
			'connect_timeout' => 0.2,
			'retry_after' => 5,
		]));
		
		$first = $connection->getClient();
		$started = microtime(true);
		$second = $connection->getClient();
		$third = $connection->getClient();
		
		return $first === null
			&& $second === null
			&& $third === null
			&& microtime(true) - $started < 0.1;
	}
	
	/**
	 * disconnect() drops the client: the next getClient() connects anew
	 */
	public function aDisconnectedConnectionConnectsAnew(): bool
	{
		/** @var Subject $connection */
		$connection = $this->container
			->getClass(Connections::class)
			->get('redis');
		$client = $connection->getClient();
		$closed = $connection->disconnect();
		$next = $connection->getClient();
		
		return $client instanceof RedisClient
			&& $closed
			&& $next instanceof RedisClient
			&& $next !== $client
			&& $next->ping() !== false;
	}
	
	/**
	 * Has the server kill $client's connection, through a client of its own
	 */
	protected static function killConnectionOf(
		RedisClient $client,
		Subject $connection,
	): void
	{
		preg_match('/id=(\d+)/', (string)$client->rawCommand('CLIENT', 'INFO'), $matches);
		
		$killer = new RedisClient();
		$killer->connect(
			(string)$connection->getConfig()->host,
			(int)($connection->getConfig()->port ?? 6379),
		);
		$killer->rawCommand('CLIENT', 'KILL', 'ID', $matches[1]);
		$killer->close();
	}
}
