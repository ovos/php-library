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
	// !ENV_LIST resolves an env value to a list, splitting on this separator
	public const string LIST_KEY = 'ENV_LIST';
	public const string LIST_SEPARATOR = ',';
	
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

	/**
	 * Parsing callback for the !ENV_LIST tag: resolves the env value like
	 * !ENV, then splits it on LIST_SEPARATOR into a trimmed, non-empty list.
	 * An unset key (with no "|default") yields an empty list. Lets a scalar
	 * .env value back a YAML sequence, e.g.
	 *   whitelist: !ENV_LIST UI_AUTH[WHITELIST]   # "127.0.0.1,::1" -> [...]
	 */
	public function getYamlListTag(
		mixed $value,
		string $tag,
		int $flags,
	): array
	{
		$resolved = $this->getYamlTag($value, $tag, $flags);
		if($resolved === null || $resolved === '')
		{
			return [];
		}

		$items = array_map('trim', explode(self::LIST_SEPARATOR, (string)$resolved));

		return array_values(array_filter(
			$items,
			static fn(string $item): bool => $item !== '',
		));
	}

	public function getYamlTags(): array
	{
		return
		[
			'!' . self::KEY => [$this, 'getYamlTag'],
			'!' . self::LIST_KEY => [$this, 'getYamlListTag'],
		];
	}
}
