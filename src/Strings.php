<?php
declare(strict_types=1);

namespace Ovos;

use Cocur\Slugify\Slugify;

use function ctype_lower;
use function dechex;
use function htmlspecialchars;
use function lcfirst;
use function mt_rand;
use function preg_replace;
use function random_int;
use function str_contains;
use function str_pad;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function ucwords;

/**
 * Strings
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Strings
{
	/**
	 * @deprecated
	 * @see https://wiki.php.net/rfc/str_starts_with
	 */
	public static function startsWith(
		string $haystack,
		string $needle,
	): bool
	{
		return str_starts_with($haystack, $needle);
	}
	
	/**
	 * @deprecated
	 * @see https://wiki.php.net/rfc/str_contains
	 */
	public static function contains(
		string $haystack,
		string $needle,
	): bool
	{
		return str_contains($haystack, $needle);
	}
	
	/**
	 * Returns the slug-version of the string.
	 */
	public static function slugify(
		string $string,
	): string
	{
		$slugify = new Slugify;
		return $slugify->slugify($string);
	}
	
	public static function studlyCase(
		string $string,
	): string
	{
		$string = ucwords(str_replace(array('-', '_'), ' ', $string));
		return str_replace(' ', '', $string);
	}
	
	public static function camelCase(
		string $string,
	): string
	{
		return lcfirst(self::studlyCase($string));
	}
	
	public static function snakeCase(
		string $value,
		string $delimiter = '-',
	): string
	{
		if(ctype_lower($value) === false)
		{
			$value = strtolower(preg_replace('/(.)(?=[A-Z])/u', 
				'$1' . $delimiter,
				$value,
			));
		}
		
		return $value;
	}
	
	/**
	 * Generate random string
	 */
	public static function random(
		int $length = 32,
		string $characters = 'abcdefghijkmnopqrstuvwxyz0123456789',
	): string
	{
		$str = '';
		$i = 0;
		while($i < $length)
		{
			$str.= $characters[mt_rand() % strlen($characters)];
			$i++;
		}
		return $str;
	}
	
	public static function randomColorPart(): string
	{
		return str_pad(
			dechex(random_int(0, 255)),
			2,
			'0',
			STR_PAD_LEFT,
		);
	}
	
	public static function randomColor(): string
	{
		return self::randomColorPart()
			. self::randomColorPart()
			. self::randomColorPart();
	}
	
	/**
	 * Escapes the string for safe display inside HTML
	 */
	public static function escapeForHtml(
		string $string,
	): string
	{
		return htmlspecialchars($string,
			ENT_QUOTES | ENT_SUBSTITUTE,
			'UTF-8',
		);
	}
	
	public static function wrap(
		string $string,
		string $wrap,
	): string
	{
		return $wrap . $string . $wrap;
	}
	
	public static function entities(
		string $string,
	): string
	{
		$strlen = strlen($string);
		$return = '';
		
		for($i = 0; $i < $strlen; $i++)
		{
			$return.= '&#' . ord($string[$i]) . ';';
		}
		
		return $return;
	}
	
	/**
	 * Shortens the string to the given maximum length, appending a specified ending if necessary
	 */
	public static function shorten(
		string $string,
		int $length, // the maximum length of the string including the ending
		string $ending = '...', // the string to append if truncation is necessary
	): string
	{
		if(mb_strlen($string) <= $length)
		{
			return $string;
		}
		
		return mb_strimwidth($string, 0, $length, $ending);
	}
}
