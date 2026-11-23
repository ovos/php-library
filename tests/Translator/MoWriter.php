<?php
declare(strict_types=1);

namespace Tests\Translator;

use Ovos\Test;
use Ovos\Translator\MoParser;
use Ovos\Translator\MoWriter as Subject;
use Ovos\Translator\Po;

use function chr;
use function file_get_contents;
use function implode;
use function is_file;
use function ksort;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const SORT_STRING;

/**
 * Translator\MoWriter - the .mo msgfmt writes, read back by MoParser
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class MoWriter extends Test
{
	public function theParserReadsBackWhatTheWriterWrote(): bool
	{
		$po = Po::fromString(implode("\n", [
			'msgid ""',
			'msgstr ""',
			'"Language: pl\n"',
			'"POT-Creation-Date: 2026-01-02 03:04+0100\n"',
			'"Content-Type: text/plain; charset=UTF-8\n"',
			'',
			'msgid "Open"',
			'msgstr "Otwarte"',
			'',
			'msgctxt "verb"',
			'msgid "Open"',
			'msgstr "Otwórz"',
			'',
			'msgid "{n} file"',
			'msgid_plural "{n} files"',
			'msgstr[0] "{n} plik"',
			'msgstr[1] "{n} pliki"',
			'msgstr[2] "{n} plików"',
			'',
			'msgctxt "inbox"',
			'msgid "{n} message"',
			'msgid_plural "{n} messages"',
			'msgstr[0] "{n} wiadomość"',
			'msgstr[1] "{n} wiadomości"',
			'msgstr[2] "{n} wiadomości"',
			'',
			'#, fuzzy',
			'msgid "Close"',
			'msgstr "Zamknij"',
			'',
			'msgid "Save"',
			'msgstr ""',
			'',
			'#~ msgid "Delete"',
			'#~ msgstr "Usuń"',
			'',
		]));
		
		$path = (string)tempnam(sys_get_temp_dir(), 'mo');
		try
		{
			(new Subject)->write($po, $path);
			$read = (new MoParser($path))->parse();
		}
		finally
		{
			if(is_file($path))
			{
				unlink($path);
			}
		}
		
		ksort($read, SORT_STRING);
		
		// msgfmt leaves POT-Creation-Date out of the header
		return $read === [
			'' => "Language: pl\nContent-Type: text/plain; charset=UTF-8\n",
			'Open' => 'Otwarte',
			'inbox' . chr(4) . '{n} message' . chr(0) . '{n} messages'
				=> '{n} wiadomość' . chr(0) . '{n} wiadomości' . chr(0) . '{n} wiadomości',
			'verb' . chr(4) . 'Open' => 'Otwórz',
			'{n} file' . chr(0) . '{n} files' => '{n} plik' . chr(0) . '{n} pliki' . chr(0) . '{n} plików',
		];
	}
	
	/**
	 * pl.mo beside the fixture is msgfmt's, hash table included
	 */
	public function writesTheBytesMsgfmtWrites(): bool
	{
		return (new Subject)->toString(Po::fromFile(__DIR__ . '/files/pl.po'))
			=== file_get_contents(__DIR__ . '/files/pl.mo');
	}
}
