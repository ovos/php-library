<?php
declare(strict_types=1);

namespace Ovos;

use Cocur\Slugify\Slugify;

use function str_starts_with;
use function str_contains;
use function ucwords;
use function str_replace;
use function lcfirst;
use function str_pad;
use function ctype_lower;
use function strtolower;
use function preg_replace;
use function dechex;
use function random_int;
use function mt_rand;
use function strlen;
use function htmlspecialchars;

/**
 * Strings
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Strings
{
	/**
	 * @deprecated 
	 * 
	 * @param string $haystack
	 * @param string $needle
	 *
	 * @return bool
	 */
	public static function startsWith(string $haystack, string $needle): bool
	{
		return str_starts_with($haystack, $needle);
	}
	
	/**
	 * @deprecated @see https://wiki.php.net/rfc/str_contains
	 * 
	 * @param string $haystack
	 * @param string $needle
	 *
	 * @return bool
	 */
	public static function contains(string $haystack, string $needle): bool
	{
		return str_contains($haystack, $needle);
	}
	
	/**
	 * Returns the slug-version of the string.
	 *
	 * @param string $string
	 *
	 * @return string
	 */
	public static function slugify(string $string): string
	{
		$slugify = new Slugify;
		return $slugify->slugify($string);
	}
	
	/**
	 * @param string $string
	 *
	 * @return string
	 */
	public static function studlyCase(string $string): string
	{
		$string = ucwords(str_replace(array('-', '_'), ' ', $string));
		return str_replace(' ', '', $string);
	}
	
	/**
	 * @param string $string
	 *
	 * @return string
	 */
	public static function camelCase(string $string): string
	{
		return lcfirst(self::studlyCase($string));
	}
	
	/**
	 * @param string $value
	 * @param string $delimiter
	 *
	 * @return string
	 */
	public static function snakeCase(string $value, string $delimiter = '-'): string
	{
		if(ctype_lower($value) === false)
		{
			$value = strtolower(preg_replace('/(.)(?=[A-Z])/u', '$1' . $delimiter, $value));
		}
		
		return $value;
	}
	
	/**
	 * Generate random string
	 *
	 * @param int $length
	 * @param string $characters
	 *
	 * @return string
	 */
	public static function random(int $length = 32,
		string $characters = 'abcdefghijkmnopqrstuvwxyz0123456789'
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
	
	/**
	 * @return string
	 */
	public static function randomColorPart(): string
	{
		return str_pad(dechex(random_int(0, 255)), 2, '0', STR_PAD_LEFT);
	}
	
	/**
	 * @return string
	 */
	public static function randomColor(): string
	{
		return self::randomColorPart() . self::randomColorPart() . self::randomColorPart();
	}
	
	/**
	 * Escapes the string for safe display inside HTML
	 *
	 * @param string $string
	 *
	 * @return string
	 */
	public static function escapeForHtml(string $string): string
	{
		return htmlspecialchars($string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
	
	/**
	 * @param string $string
	 * @param string $wrap
	 *
	 * @return string
	 */
	public static function wrap(string $string, string $wrap): string
	{
		return $wrap . $string . $wrap;
	}
	
	/**
	 * @param string $string
	 *
	 * @return string
	 */
	public static function entities(string $string): string
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
	 * 
	 * @param string $string The input string
	 * @param int $length The maximum length of the string including the ending
	 * @param string $ending The string to append if truncation is necessary
	 * 
	 * @return string The shortened string
	 */
	public static function shorten(string $string, int $length, string $ending = '...'): string
	{
		if(mb_strlen($string) <= $length)
		{
			return $string;
		}
		
		return mb_strimwidth($string, 0, $length, $ending);
	}
}
