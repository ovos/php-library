<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Environment\Parser;

/**
 * Environment
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Environment
{
	public const string PRODUCTION = 'production';
	public const string FILE = '.env';
	public const string KEY = 'ENV';
	
	protected string $env = self::PRODUCTION;
	
	protected array $config;
	
	protected array $flat;
	
	public function __construct(
		array $config = [],
	)
	{
		$this->config = $config;
		if(isset($this->config[self::KEY]))
		{
			$this->env = $this->config[self::KEY];
		}
		
		$this->flat = Arrays::flatten($config);
	}
	
	public function getEnv(): string
	{
		return $this->env;
	}
	
	public function getConfig(): array
	{
		return $this->config;
	}
	
	public function asFlatArray(): array
	{
		return $this->flat;
	}
	
	/**
	 * Parsing callback for YAML tag.
	 */
	public function getYamlTag(
		mixed $value, // data from the YAML file
		string $tag, // tag that triggered callback
		int $flags, // scalar entity style (see YAML_*_SCALAR_STYLE)
	): mixed // value that YAML parser should emit for the given value
	{
		$default = null;
		if(str_contains($value, '|'))
		{
			[$value, $default] = explode('|', $value);
			$default = Parser::parseValue($default);
		}
		
		if(isset($this->flat[$value]))
		{
			return $this->flat[$value];
		}
		
		return $default;
	}
	
	public function getYamlTags(): array
	{
		return
		[
			'!' . self::KEY => [$this, 'getYamlTag'],
		];
	}
}
