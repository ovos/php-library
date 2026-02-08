<?php
declare(strict_types=1);

namespace Ovos\Environment;

use function parse_ini_file;

/**
 * Parser
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Parser
{
	public static function parse(
		string $file,
	): ?array
	{
		$result = parse_ini_file($file, true,
			INI_SCANNER_TYPED);
		if($result === false)
		{
			return null;
		}
		
		return $result;
	}
	
	/**
	 * This method simulates the behavior of INI_SCANNER_TYPED option
	 * It can be used to parse values coming from other sources than an INI file
	 * We use it to parse a default value specified next to !ENV YAML tags,
	 * which identify specific entries in .env file
	 */
	public static function parseValue(
		string $value,
	): mixed
	{
		// convert to lowercase for case-insensitive keyword matching later
		$lowerValue = strtolower($value);
		
		// attempt to parse as an integer
		// this must be done before checking boolean/null keywords to ensure
		// numeric strings like "0" or "1" are treated as integers, not booleans
		$intValue = filter_var($value, FILTER_VALIDATE_INT);
		if($intValue !== false)
		{
			return $intValue;
		}
		
		// attempt to parse as a float
		// this comes after int parsing, as an integer is also a float
		$floatValue = filter_var($value, FILTER_VALIDATE_FLOAT);
		if($floatValue !== false)
		{
			return $floatValue;
		}
		
		// handle specific boolean and null keywords (case-insensitive)
		switch($lowerValue)
		{
			case 'true':
			case 'on':
			case 'yes':
				return true;
			case 'false':
			case 'off':
			case 'no':
			case 'none': // crucial: 'none' is explicitly converted to false
				return false;
			case 'null': // explicitly convert 'null' string to PHP's null
				return null;
		}
		
		// if no specific type conversion was successful, return the original string
		return $value;
	}
}
