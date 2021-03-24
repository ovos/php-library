<?php
declare(strict_types=1);

namespace Ovos;

/**
 * ArrayObject
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Environment
{
	/**#@+
	 * Environment constants
	 */
	public const ENV_PRODUCTION = 'production';
	public const ENV_FILE = 'env';
	public const ENV_KEY = 'ENV';
	/**#@-*/
	
	/**
	 * @var string 
	 */
	protected string $_env = self::ENV_PRODUCTION;

	/**
	 * @var array 
	 */
	protected array $_config;

	/**
	 * @var array
	 */
	protected array $_flat;

	/**
	 * @param array $config
	 */
	public function __construct(array $config)
	{
		$this->_config = $config;
		if(isset($this->_config[self::ENV_KEY]))
		{
			$this->_env = $this->_config[self::ENV_KEY];
		}

		$this->_flat = Arrays::flatten($config);
	}

	/**
	 * @return string
	 */
	public function getEnv(): string
	{
		return $this->_env;
	}
	
	/**
	 * @return array
	 */
	public function getConfig(): array
	{
		return $this->_config;
	}

	/**
	 * @return array
	 */
	public function asFlatArray(): array
	{
		return $this->_flat;
	}

	/**
	 * @return array
	 */
	public function getYamlTags(): array
	{
		$tags = [];
		
		foreach($this->_flat as $key => $replaceValue)
		{
			$tags['!' . $key] = static function($value, $tag, $flags) use($replaceValue) 
			{
				return $replaceValue;
			};
		}
		
		return $tags;
	}
}
