<?php
declare(strict_types=1);

namespace Ovos;

use function nl2br;
use function sprintf;

/**
 * Functions
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Functions
{
	public static function println(
		string $line = '',
		bool $bold = false,
		bool $indent = false,
	): void
	{
		$isCli = PHP_SAPI === 'cli';
		
		// indent
		if($indent)
		{
			if($isCli)
			{
				$line = "\t" . $line;
			}
			else
			{
				$line = '&emsp;' . $line;
			}
		}
		
		// bold
		if(!$isCli && $bold)
		{
			$line = sprintf('<b>%s</b>', $line);
		}
		
		$line.= PHP_EOL;
		
		Terminal::output($isCli
			? $line
			: nl2br($line),
			// supportsColor() is false for every non-CLI SAPI, which is what keeps
			// <color> tokens out of the HTML branch
			Terminal::supportsColor(),
		);
	}
}
