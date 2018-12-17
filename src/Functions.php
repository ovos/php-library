<?php
declare(strict_types=1);

namespace Ovos;

/**
 * Functions
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Functions
{
	/**
	 * @param string $line
	 * @param bool $bold
	 * @param bool $indent
	 *
	 * @return void
	 */
	public static function println(string $line,
		bool $bold = false,
		bool $indent = false
	): void
	{
		$isCli = PHP_SAPI === 'cli';

		// indent
		if($indent)
		{
			if($isCli)
			{
				$line = "\t". $line;
			}
			else
			{
				$line = '&emsp;'. $line;
			}
		}

		// bold
		if(!$isCli && $bold)
		{
			$line = sprintf('<b>%s</b>', $line);
		}

		$line.= PHP_EOL;

		print($isCli ? $line : nl2br($line));
	}
}
