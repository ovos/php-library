<?php
declare(strict_types=1);

namespace Ovos\Config;

use Ovos\Environment;

use function yaml_parse_file;

/**
 * Parser
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Parser
{
	public static function parse(
		string $file,
		?Environment $environment = null,
	): ?array
	{
		$result = yaml_parse_file(
			$file, 
			0, 
			$ndocs,
			$environment?->getYamlTags(),
		);
		
		if($result === false)
		{
			return null;
		}
		
		return $result;
	}
}
