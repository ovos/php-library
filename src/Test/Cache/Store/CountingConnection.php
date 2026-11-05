<?php
declare(strict_types=1);

namespace Ovos\Test\Cache\Store;

use Ovos\Connection\Redis as Connection;
use Override;
use Redis as RedisClient;

/**
 * CountingConnection
 *
 * A standalone Redis connection whose client counts its round trips (see
 * CountingClient): the connection as Connection\Redis makes it, its client
 * then replaced by a counting one with the same server, options and
 * database
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class CountingConnection extends Connection
{
	#[Override]
	public function connect(): bool
	{
		if(parent::connect() === false)
		{
			return false;
		}
		
		$plain = $this->client;
		$counting = new CountingClient([
			'host' => $plain->getHost(),
			'port' => $plain->getPort(),
			'connectTimeout' => $this->connectTimeout,
		]);
		foreach([
			RedisClient::OPT_READ_TIMEOUT,
			RedisClient::OPT_SERIALIZER,
			RedisClient::OPT_REPLY_LITERAL,
			RedisClient::OPT_MAX_RETRIES,
			RedisClient::OPT_BACKOFF_ALGORITHM,
			RedisClient::OPT_BACKOFF_BASE,
			RedisClient::OPT_BACKOFF_CAP,
			RedisClient::OPT_TCP_KEEPALIVE,
		] as $option)
		{
			$counting->setOption($option, $plain->getOption($option));
		}
		$counting->select($plain->getDbNum());
		$plain->close();
		$this->client = $counting;
		
		return true;
	}
	
	/**
	 * The counting client (connected on demand)
	 */
	public function counter(): ?CountingClient
	{
		$client = $this->getClient();
		
		return $client instanceof CountingClient
			? $client
			: null;
	}
}
