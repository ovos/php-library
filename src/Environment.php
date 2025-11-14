<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Environment\Parser;

/**
 * ArrayObject
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Environment
{
	/**#@+
	 * Environments
	 */
	public const string ENV_PRODUCTION = 'production';
	public const string ENV_FILE = '.env';
	public const string ENV_KEY = 'ENV';
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
	public function __construct(array $config = [])
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
	/**
	 * Parsing callback for YAML tag.
	 * 
	 * @param mixed $value Data from the YAML file
	 * @param string $tag Tag that triggered callback
	 * @param int $flags Scalar entity style (see YAML_*_SCALAR_STYLE)
	 * 
	 * @return mixed Value that YAML parser should emit for the given value
	 */
	public function getYamlTag(mixed $value, string $tag, int $flags): mixed
	{
		$default = null;
		if(str_contains($value, '|'))
		{
			[$value, $default] = explode('|', $value);
			$default = Parser::parseValue($default);
		}
		
		if(isset($this->_flat[$value]))
		{
			return $this->_flat[$value];
		}
		
		return $default;
	}
	
	/**
	 * @return array
	 */
	public function getYamlTags(): array
	{
		return
		[
			'!' . self::ENV_KEY => [$this, 'getYamlTag'],
		];
	}
}
