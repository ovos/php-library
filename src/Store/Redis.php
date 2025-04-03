<?php
declare(strict_types=1);

namespace Ovos\Store;

use Ovos\Exception;
use Ovos\ArrayObject;
use Ovos\Redis\Connection;
use Redis as BaseRedis;
use RedisException;

use function is_int;

/**
 * Redis
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Redis extends Cache
{
	/**
	 * Redis connection
	 *
	 * @var ?Connection
	 */
	protected ?Connection $_connection = null;
	
	/**
	 * @var ?string 
	 */
	protected ?string $_prefix = null;
	
	/**
	 * @var string
	 */
	protected string $_hash = 'cache';
	
	/**
	 * @var int
	 */
	protected int $_multiMode = BaseRedis::PIPELINE;
	
	/**#@+
	 * Separators
	 */
	public const string SEPARATOR_PREFIX = ':';
	/**#@-*/
	
	/**#@+
	 * Keys
	 */
	public const string KEY_DATA = 'data';
	/**#@-*/	
		
	/**#@+
	 * Functions
	 */
	public const FUNCTION_BATCHES = 'batches';
	/**#@-*/		
		
	/**#@+
	 * Statuses
	 * Used for rawCommand, which returns strings instead of boolean values when OPT_REPLY_LITERAL is enabled
	 * @see https://github.com/phpredis/phpredis/issues/1550
	 */
	public const STATUS_OK = 'OK';
	/**#@-*/	
	
	/**
	 * @param ArrayObject $config
	 */
	public function __construct(ArrayObject $config)
	{
		parent::__construct();
	
		if($config->offsetExists('prefix') === false)
		{
			throw new Exception('"cache: prefix" is a required config value.');
		}
		
		$this->setPrefix($config->prefix);
		$this->setConfig($config->persistent);
	}
	
	/**
	 * @param ?string $prefix
	 *
	 * @return self
	 */
	public function setPrefix(?string $prefix = null): self
	{
		$this->_prefix = $prefix;
		
		return $this;
	}
	
	/**
	 * @param string $key
	 * @param ?string $prefix
	 *
	 * @return string
	 */
	public function prefix(string $key, ?string $prefix = null): string
	{
		return ($prefix ?: $this->_prefix) . self::SEPARATOR_PREFIX . $key;
	}	
	
	/**
	 * @return bool
	 */
	public function connect(): bool
	{
		$this->_connection = new Connection($this->_config);
		return $this->_connection->connect();
	}

	/**
	 * @return ?BaseRedis
	 */
	public function getClient(): ?BaseRedis
	{
		return $this->_connection->getClient();
	}
	
	/**
	 * @return string
	 */
	public function getHashName(): string
	{
		return $this->prefix($this->_hash);
	}
	
	/**
	 * @param string $key
	 *
	 * @return null|mixed
	 */
	public function get(string $key): mixed
	{
		if(($client = $this->getClient()) === null)
		{
			return null;	
		}
		
		$value = $client->hGet(
			$this->prefix($key, $this->getHashName()),
			self::KEY_DATA,
		);
		if($value === false)
		{
			return null;
		}
	
		return $this->unserialize($this->decompress($value));
	}
	
	/**
	 * @param string $key
	 *
	 * @return null|bool
	 */
	public function delete(string $key): null|bool
	{
		if(($client = $this->getClient()) === null)
		{
			return null;	
		}
		
		return $client->unlink(
			$this->prefix($key, $this->getHashName()),
		) > 0;
	}
	
	/**
	 * @param string $key
	 * @param mixed $value
	 * @param int $ttl
	 *
	 * @return bool
	 */
	public function set(string $key, mixed $value, int $ttl = 0): bool
	{
		if(($client = $this->getClient()) === null)
		{
			return false;	
		}
		
		$value = $this->compress($this->serialize($value));
		
		$client->multi($this->_multiMode);
		$client->hSet(
			$this->prefix($key, $this->getHashName()), 
			self::KEY_DATA, $value,
		);
		
		// set expire if needed
		if($ttl > 0)
		{
			$client->expire($key, $ttl);
		}
		$result = $client->exec();
	
		return $result[0] !== false;
	}
	
	/**
	 * @return bool|int
	 */
	public function clear(): bool|int
	{
		if(($client = $this->getClient()) === null)
		{
			return false;	
		}
		
		$script = '';
		$script.= $this->getFunction(self::FUNCTION_BATCHES);
		$script.= "
			local prefix = ARGV[1]
			
			-- unlink every ID
			local count = 0
			local cursor = '0'
			repeat
				local results = redis.call('SCAN', cursor,
					'MATCH', prefix,
					'COUNT', 5000
				)
				cursor = results[1]
				local ids = {}
				
				for _, id in ipairs(results[2]) do
					table.insert(ids, id)
				end
				
				if #ids > 0 then
					count = count + #ids
					for from, to in batches(#ids) do -- security measure, not really needed with 5000 batch size
						redis.call('UNLINK', unpack(ids, from, to))
					end
				end
			until '0' == cursor
			
			return count -- return count of deleted ids
		";		
		
		$hashName = $this->getHashName();
		$prefix = $this->prefix('*', $hashName);
		$count = 0;
		
		$client->clearLastError();
		
		$args = [$prefix];
		$result = $client->eval($script, $args, 0);
		if(is_int($result))
		{
			$count = $result;
		}
		
		if($error = $client->getLastError())
		{
			throw new RedisException($error);
		}
		
		return $count;
	}
	
	/**
	 * @param string $name
	 *
	 * @return string
	 */
	public function getFunction(string $name): string
	{
		return match($name)
		{
			'batches' => "
				local function batches(n, batchSize)
					batchSize = batchSize or 7500
					local i = 0
					
					return function()
						local from = i * batchSize + 1
						i = i + 1
						if (from <= n) then
							local to = math.min(from + batchSize - 1, n)
							return from, to
						end
					end
				end
			",
		};
	}
}
