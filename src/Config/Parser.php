<?php
declare(strict_types=1);

namespace Ovos\Config;

use Ovos\Exception;

/**
 * Parser
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Parser
{
	/**
	 * @param string $file
	 * @return array|null
	 */
	public static function parse(string $file): ?array
	{
		$result = yaml_parse_file($file);
		if($result === false)
		{
			return null;
		}

		return $result;
	}
}
