<?php
declare(strict_types=1);

namespace Ovos\Translator;

use Ovos\Translator\StringReader\Exception;

use function file_get_contents;
use function strlen;
use function substr;
use function unpack;

use const PHP_INT_MAX;

/**
 * StringReader
 *
 * Modified StringReader class from motranslator
 * @see https://github.com/phpmyadmin/motranslator
 *
 * Simple wrapper around a string buffer for
 * random access and value parsing.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class StringReader
{
	protected string $string;
	
	protected int $length;
	
	public function __construct(
		string $filename, // name of the file to load
	)
	{
		$this->string = (string)file_get_contents($filename);
		$this->length = strlen($this->string);
	}
	
	/**
	 * Read the number of bytes from a given offset.
	 */
	public function read(
		int $position,
		int $bytes,
	): string
	{
		if($position + $bytes > $this->length)
		{
			throw new Exception('Not enough bytes!');
		}
		
		return substr($this->string, $position, $bytes);
	}
	
	/**
	 * Reads a 32bit integer from the stream.
	 */
	public function readInt(
		string $unpack, // unpack string
		int $position,
	): int // integer from the stream
	{
		$data = unpack($unpack, $this->read($position, 4));
		if($data === false)
		{
			return PHP_INT_MAX;
		}
		
		$result = $data[1];
		
		/* We're reading unsigned int, but PHP will happily
		 * give us a negative number on 32-bit platforms.
		 *
		 * See also documentation:
		 * https://secure.php.net/manual/en/function.unpack.php#refsect1-function.unpack-notes
		 */
		return $result < 0 ? PHP_INT_MAX : $result;
	}
	
	/**
	 * Reads an array of integers from the stream.
	 * 
	 * @return int[] array of Integers
	 */
	public function readIntArray(
		string $unpack, // unpack string
		int $position,
		int $count, // how many elements should be read?
	): array
	{
		$data = unpack($unpack . $count, $this->read($position, 4 * $count));
		if($data === false)
		{
			return [];
		}
		
		return $data;
	}
}
