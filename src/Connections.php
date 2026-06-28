<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Container\ArrayObject as InjectArrayObject;
use Ovos\Container\Inject;
use Ovos\Exception\InvalidException\InvalidClassException;
use Ovos\Exception\MissingException\MissingConfigException;

/**
 * Connections
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Connections
{
	#[Inject]
	protected Container $container;
	
	#[Inject('config')]
	#[InjectArrayObject('connections')]
	protected ArrayObject $config;
	
	public function get(
		string $name,
		?string $modifier = null,
	): Connection
	{
		$config = $this->getConnectionConfig($name);
		$class = $this->getConnectionClass($config->type);
		
		$id = 'connection.%s.%s';
		if($modifier !== null)
		{
			$id .= '.' . $modifier;
		}
		
		$connectionId = sprintf($id,
			$config->type,
			$class::getId($config),
		);
		
		return $this->container->getClass(
			$connectionId,
			$class,
			[
				'config' => $config,
			],
		);
	}
	
	public function getConnectionConfig(
		string $name,
	): ArrayObject
	{
		foreach($this->config as $configName => $config)
		{
			if($configName !== $name)
			{
				continue;
			}
			
			return $config;
		}
		
		throw new MissingConfigException(
			'Missing connection config for: ' . $name
		);
	}
	
	public function getConnectionClass(
		string $type,
	): string
	{
		return match($type)
		{
			'redis' => Connection\Redis::class,
			'redis_cluster' => Connection\RedisCluster::class,
			'mysql' => Connection\Mysql::class,
			default => throw new InvalidClassException(
			'Unknown connection type: ' . $type
			),
		};
	}
	
	public function getConfig(): ArrayObject
	{
		return $this->config;
	}
}
