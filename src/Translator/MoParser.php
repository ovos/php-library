<?php

declare(strict_types=1);

namespace Ovos\Translator;

use Ovos\Translator\StringReader\Exception;

use function is_readable;
use function strcmp;

/**
 * MoParser
 * 
 * Modified on MoParser class from motranslator
 * @see https://github.com/phpmyadmin/motranslator
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
final class MoParser
{
	/**#@+
	 * Errors
	 */
	/**
	 * File does not exist
	 */
	public const int ERROR_DOES_NOT_EXIST = 1;
	
	/**
	 * File has bad magic number
	 */
	public const int ERROR_BAD_MAGIC = 2;
	
	/**
	 * Error while reading file, probably too short
	 */
	public const int ERROR_READING = 3;
	/**#@-*/
	
	/**#@+
	 * Magic bytes constants
	 */
	/**
	 * Big endian mo file magic bytes
	 */
	public const string MAGIC_BE = "\x95\x04\x12\xde";
	/**
	 * Little endian mo file magic bytes
	 */
	public const string MAGIC_LE = "\xde\x12\x04\x95";
	/**#@-*/
	
	/**
	 * Parse error code (null if no error).
	 *
	 * @var ?int
	 */
	protected ?int $_error = null;
	
	/**
	 * @var ?string 
	 */
	protected ?string $_filename = null;
	
	/**
	 * @var ?array
	 */
	protected ?array $_translations = null;
	
	/**
	 * @param ?string $filename
	 */
	public function __construct(?string $filename = null)
	{
		$this->_filename = $filename;
	}
	
	/**
	 * @return array
	 */
	public function getTranslations(): array
	{
		return $this->_translations ?? $this->parse();
	}
	
	/**
	 * @return bool
	 */
	public function hasError(): bool
	{
		return $this->_error !== null;
	}
	
	/**
	 * @return ?int
	 */
	public function getError(): ?int
	{
		return $this->_error;
	}
	
	/**
	 * Parses .mo file and stores phrases into an array
	 */
	public function parse(): array
	{
		$this->_translations = [];
		
		if($this->_filename === null)
		{
			return [];
		}
		
		if(!is_readable($this->_filename))
		{
			$this->_error = self::ERROR_DOES_NOT_EXIST;
			
			return [];
		}
		
		$stream = new StringReader($this->_filename);
		
		try
		{
			$magic = $stream->read(0, 4);
			
			if(strcmp($magic, self::MAGIC_LE) === 0)
			{
				$unpack = 'V';
			}
			else if(strcmp($magic, self::MAGIC_BE) === 0)
			{
				$unpack = 'N';
			}
			else
			{
				$this->_error = self::ERROR_BAD_MAGIC;
				
				return [];
			}
			
			/* parse header */
			$total = $stream->readInt($unpack, 8);
			$originals = $stream->readInt($unpack, 12);
			$translations = $stream->readInt($unpack, 16);
			
			/* get original and translations tables */
			$totalTimesTwo = ($total * 2);
			$tableOriginals = $stream->readIntArray($unpack, $originals, $totalTimesTwo);
			$tableTranslations = $stream->readIntArray($unpack, $translations, $totalTimesTwo);
			
			/* read all strings to the cache */
			for($i = 0; $i < $total; ++$i)
			{
				$iTimesTwo = $i * 2;
				$iPlusOne = $iTimesTwo + 1;
				$iPlusTwo = $iTimesTwo + 2;
				$original = $stream->read($tableOriginals[$iPlusTwo], $tableOriginals[$iPlusOne]);
				$translation = $stream->read($tableTranslations[$iPlusTwo], $tableTranslations[$iPlusOne]);
				
				$this->_translations[$original] = $translation;
			}
			
		}
		catch(Exception $exception)
		{
			$this->_error = self::ERROR_READING;
			
			return [];
		}
		
		return $this->_translations;
	}
}
