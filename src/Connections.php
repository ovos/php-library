<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\ArrayObject;
use Ovos\Container\ArrayObject as InjectArrayObject;
use Ovos\Container\Inject;
use Ovos\Exception\InvalidException\InvalidClassException;
use Ovos\Exception\MissingException\MissingConfigException;

/**
 * Connections
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Connections
{
	/**
	 * @var Container
	 */
	#[Inject]
	protected Container $_container;
	
	/**
	 * @var ArrayObject
	 */
	#[Inject('config')]
	#[InjectArrayObject('connections')]
	protected ArrayObject $_config;
	
	/**
	 * @param string $name
	 *
	 * @return Connection
	 */
	public function get(string $name): Connection
	{
		$config = $this->getConnectionConfig($name);
		$class = $this->getConnectionClass($config->type);
		
		$connectionId = sprintf('connection.%s.%s',
			$config->type,
			$class::getId($config),
		);
		
		return $this->_container->getClass($connectionId, $class, [
			'config' => $config,
		]);
	}
	
	/**
	 * @param string $name
	 *
	 * @return ArrayObject
	 */
	public function getConnectionConfig(string $name): ArrayObject
	{
		foreach($this->_config as $configName => $config)
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
	
	/**
	 * @param string $type
	 *
	 * @return string
	 */
	public function getConnectionClass(string $type): string
	{
		return match($type)
		{
			'redis' => Connection\Redis::class,
			'mysql' => Connection\Mysql::class,
			default => throw new InvalidClassException(
			'Unknown connection type: ' . $type
			),
		};
	}
	
	/**
	 * @return ArrayObject
	 */
	public function getConfig(): ArrayObject
	{
		return $this->_config;
	}
}
