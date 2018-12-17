<?php
declare(strict_types=1);

namespace Ovos;

/**
 * File
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class File
{
	/**
	 * Extract basename and extension
	 *
	 * @param string $filename
	 *
	 * @return array
	 */
	public static function extractFilename(string $filename): array
	{
		$basename = $ext = null;

		$dotPos = mb_strrpos($filename, '.');
		if($dotPos > 0)
		{
			$extLength = mb_strlen($filename) - $dotPos - 1;
			if($extLength > 0 && $extLength <= 15)
			{
				$ext = mb_substr($filename, $dotPos + 1);
				$basename = mb_substr($filename, 0, $dotPos);
			}
		}

		return [$basename, $ext];
	}
}
