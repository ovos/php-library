<?php
declare(strict_types=1);

namespace Ovos\Translator;

use Ovos\Translator\Catalog\Exception;

use function array_keys;
use function chr;
use function count;
use function file_put_contents;
use function implode;
use function intdiv;
use function is_file;
use function ksort;
use function ord;
use function pack;
use function preg_replace;
use function rename;
use function sprintf;
use function strlen;
use function strpos;
use function substr;
use function unlink;

use const SORT_STRING;

/**
 * MoWriter - compiles a .po into the .mo MoParser reads, as GNU msgfmt does
 *
 * The header goes in, and every entry whose first form is translated, is
 * not fuzzy and is not "#~". A context is joined to its msgid with chr(4), a
 * plural msgid to the singular with chr(0), and plural forms with chr(0).
 * The file is little-endian with msgfmt's hash table, so gettext itself can
 * read it too.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class MoWriter
{
	/**
	 * Size of the .mo header without the fields for system-dependent strings
	 */
	protected const int HEADER_SIZE = 28;
	
	/**
	 * The translations msgfmt compiles from a .po, keyed and sorted as in
	 * the .mo
	 *
	 * @return array<string, string>
	 */
	public function getTranslations(
		Po $po,
	): array
	{
		$translations = [];
		foreach($po->getEntries() as $entry)
		{
			// a fuzzy header still goes in, as in msgfmt
			if($entry->obsolete
				|| $entry->isTranslated() === false
				|| ($entry->isFuzzy() && $entry->isHeader() === false))
			{
				continue;
			}
			
			$translations[$entry->getMoKey()] = $entry->isHeader()
				? self::getHeader($entry->translations[0])
				: implode(chr(0), $entry->translations);
		}
		
		ksort($translations, SORT_STRING);
		
		return $translations;
	}
	
	/**
	 * The header as msgfmt compiles it: without POT-Creation-Date, so a new
	 * extraction alone does not change the .mo
	 */
	protected static function getHeader(
		string $header,
	): string
	{
		return preg_replace('/^POT-Creation-Date:.*(\n|$)/m', '', $header);
	}
	
	public function write(
		Po $po,
		string $path,
	): void
	{
		// a reader never sees a half-written file
		$temporary = $path . '.tmp';
		if(file_put_contents($temporary, $this->toString($po)) === false
			|| rename($temporary, $path) === false)
		{
			if(is_file($temporary))
			{
				unlink($temporary);
			}
			
			throw new Exception(sprintf('Cannot write "%s".', $path));
		}
	}
	
	public function toString(
		Po $po,
	): string
	{
		return $this->build($this->getTranslations($po));
	}
	
	/**
	 * The .mo bytes: the header, the tables of the msgids and of the
	 * translations, the hash table, then every msgid and every translation
	 * ending with a NUL
	 *
	 * @param array<string, string> $translations sorted by key
	 */
	public function build(
		array $translations,
	): string
	{
		$count = count($translations);
		$hashSize = self::getHashSize($count);
		$originalsTable = self::HEADER_SIZE;
		$translationsTable = $originalsTable + 8 * $count;
		$hashTable = $translationsTable + 8 * $count;
		$offset = $hashTable + 4 * $hashSize;
		
		$originals = '';
		$translated = '';
		$originalsIndex = '';
		$translationsIndex = '';
		$hashes = [];
		
		foreach(array_keys($translations) as $key)
		{
			// a numeric msgid is an int key in PHP
			$key = (string)$key;
			$originalsIndex .= pack('VV', strlen($key), $offset + strlen($originals));
			$originals .= $key . chr(0);
			
			$hashes[] = self::hash($key);
		}
		
		$offset += strlen($originals);
		foreach($translations as $translation)
		{
			$translationsIndex .= pack('VV', strlen($translation), $offset + strlen($translated));
			$translated .= $translation . chr(0);
		}
		
		return pack(
			'V7',
			0x950412DE,
			0,
			$count,
			$originalsTable,
			$translationsTable,
			$hashSize,
			$hashTable,
		)
			. $originalsIndex
			. $translationsIndex
			. self::buildHashTable($hashes, $hashSize)
			. $originals
			. $translated;
	}
	
	/**
	 * Open addressing with a second hash, each slot holding an entry's
	 * number plus one, as msgfmt fills it
	 *
	 * @param int[] $hashes
	 */
	protected static function buildHashTable(
		array $hashes,
		int $size,
	): string
	{
		$slots = [];
		foreach($hashes as $number => $hash)
		{
			$slot = $hash % $size;
			if(isset($slots[$slot]))
			{
				$step = 1 + ($hash % ($size - 2));
				do
				{
					$slot = $slot >= $size - $step
						? $slot - ($size - $step)
						: $slot + $step;
				}
				while(isset($slots[$slot]));
			}
			
			$slots[$slot] = $number + 1;
		}
		
		$table = '';
		for($slot = 0; $slot < $size; $slot++)
		{
			$table .= pack('V', $slots[$slot] ?? 0);
		}
		
		return $table;
	}
	
	/**
	 * msgfmt's table size: the smallest odd prime from 4/3 of the entries,
	 * at least 3
	 */
	protected static function getHashSize(
		int $count,
	): int
	{
		$size = intdiv($count * 4, 3) | 1;
		while(self::isPrime($size) === false)
		{
			$size += 2;
		}
		
		return $size <= 2
			? 3
			: $size;
	}
	
	/**
	 * gettext's own test, which takes 1 for a prime
	 */
	protected static function isPrime(
		int $candidate,
	): bool
	{
		$divisor = 3;
		$square = 9;
		while($square < $candidate
			&& $candidate % $divisor !== 0)
		{
			$divisor++;
			$square += 4 * $divisor;
			$divisor++;
		}
		
		return $candidate % $divisor !== 0;
	}
	
	/**
	 * hashpjw over the key up to its first NUL, as gettext hashes msgids
	 */
	protected static function hash(
		string $key,
	): int
	{
		$nul = strpos($key, chr(0));
		if($nul !== false)
		{
			$key = substr($key, 0, $nul);
		}
		
		$value = 0;
		$length = strlen($key);
		for($index = 0; $index < $length; $index++)
		{
			$value = (($value << 4) + ord($key[$index])) & 0xFFFFFFFF;
			$high = $value & 0xF0000000;
			if($high !== 0)
			{
				$value ^= $high >> 24;
				$value ^= $high;
			}
		}
		
		return $value;
	}
}
