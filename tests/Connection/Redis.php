<?php
declare(strict_types=1);

namespace Tests\Connection;

use Ovos\ArrayObject;
use Ovos\Connection\Redis as Subject;
use Ovos\Connections;
use Ovos\Test;
use Redis as RedisClient;
use RedisException;

use function microtime;
use function preg_match;

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
	 * The server kills this very connection: the call that finds out fails,
	 * and the next getClient() hands out a new, working client
	 */
	public function aClientTheServerDroppedIsReplacedByANewOne(): bool
	{
		/** @var Subject $connection */
		$connection = $this->container
			->getClass(Connections::class)
			->get('redis');
		$client = $connection->getClient();

		if($client instanceof RedisClient === false
			|| preg_match('/id=(\d+)/', (string)$client->rawCommand('CLIENT', 'INFO'), $matches) !== 1)
		{
			return false;
		}

		// another client has the server kill this one
		$killer = new RedisClient();
		$killer->connect(
			(string)$connection->getConfig()->host,
			(int)($connection->getConfig()->port ?? 6379),
		);
		$killer->rawCommand('CLIENT', 'KILL', 'ID', $matches[1]);
		$killer->close();

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
}
