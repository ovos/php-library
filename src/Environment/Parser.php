<?php
declare(strict_types=1);

namespace Ovos\Environment;

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
	 * @return null|array
	 */
	public static function parse(string $file): ?array
	{
		$result = parse_ini_file($file, true, INI_SCANNER_TYPED);
		if($result === false)
		{
			return null;
		}

		return $result;
	}
}
