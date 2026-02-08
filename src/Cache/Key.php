<?php
declare(strict_types=1);

namespace Ovos\Cache;

use function mb_strtolower;
use function str_replace;
use function substr;
use function trim;

/**
 * Key
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Key
{
	/**
	 * Returns a "key" to be used as a cache key from a file path string
	 * For example: /home/user/my-file.txt -> user-my-file-txt
	 * or C:\Users\User\Desktop\my-file.txt -> user-my-file-txt
	 */
	public static function fromPath(
		string $string,
	): string
	{
		$string = mb_strtolower($string);
		
		if(substr($string, 1, 2) === ':\\') // windows drive
		{
			$string = substr($string, 3);
		}
		
		$string = str_replace([
			'/',
			'\\',
			'.', // dot
		], '-', $string);
		
		$string = trim($string, '-');
		
		return $string;
	}
}
