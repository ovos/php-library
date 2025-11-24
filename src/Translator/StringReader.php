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
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class StringReader
{
	/**
	 * @var string
	 */
	protected string $_string;
	
	/**
	 * @var int
	 */
	protected int $_length;
	
	/**
	 * @param string $filename Name of a file to load
	 */
	public function __construct(string $filename)
	{
		$this->_string = (string)file_get_contents($filename);
		$this->_length = strlen($this->_string);
	}
	
	/**
	 * Read the number of bytes from a given offset.
	 *
	 * @param int $pos Offset
	 * @param int $bytes Number of bytes to read
	 * 
	 * @return string
	 */
	public function read(int $pos, int $bytes): string
	{
		if($pos + $bytes > $this->_length)
		{
			throw new Exception('Not enough bytes!');
		}
		
		return substr($this->_string, $pos, $bytes);
	}
	
	/**
	 * Reads a 32bit integer from the stream.
	 *
	 * @param string $unpack Unpack string
	 * @param int $pos Position
	 *
	 * @return int Integer from the stream
	 */
	public function readInt(string $unpack, int $pos): int
	{
		$data = unpack($unpack, $this->read($pos, 4));
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
	 * @param string $unpack Unpack string
	 * @param int $pos Position
	 * @param int $count How many elements should be read?
	 *
	 * @return int[] Array of Integers
	 */
	public function readIntArray(string $unpack, int $pos, int $count): array
	{
		$data = unpack($unpack . $count, $this->read($pos, 4 * $count));
		if($data === false)
		{
			return [];
		}
		
		return $data;
	}
}
