<?php
declare(strict_types=1);

namespace Tests\Translator;

use Ovos\Test;
use Ovos\Translator\Catalog\Exception;
use Ovos\Translator\Po as Subject;
use Ovos\Translator\Po\Entry;

use function array_map;
use function file_get_contents;
use function implode;
use function str_contains;
use function str_replace;

/**
 * Translator\Po - a .po read and written as msgcat writes it, and merged
 * with the code's msgids as msgmerge --no-fuzzy-matching merges them
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Po extends Test
{
	/**
	 * An excerpt of a file Poedit saved: wrapped lines, "\n" in a text,
	 * references over two lines, "#~" entries with a plural and a fuzzy one
	 */
	protected const string POEDIT = __DIR__ . '/files/poedit.po';
	
	protected const array HEADER = [
		'msgid ""',
		'msgstr ""',
		'"Language: pl\n"',
		'"Content-Type: text/plain; charset=UTF-8\n"',
		'"Plural-Forms: nplurals=3; plural=(n==1 ? 0 : n%10>=2 && n%10<=4 && (n%100<12 "',
		'"|| n%100>14) ? 1 : 2);\n"',
		'',
	];
	
	/**
	 * A msgid as Extractor hands it over
	 */
	protected static function found(
		string $msgid,
		?string $plural,
		array $references,
		array $comments = [],
	): Entry
	{
		$entry = new Entry($msgid, $plural);
		$entry->references = $references;
		$entry->extractedComments = $comments;
		
		return $entry;
	}
	
	/**
	 * The message of the refusal, null when the text is read
	 */
	protected static function getRefusal(
		string $text,
	): ?string
	{
		try
		{
			Subject::fromString($text, 'pl.po');
		}
		catch(Exception $exception)
		{
			return $exception->getMessage();
		}
		
		return null;
	}
	
	public function aFileSavedByPoeditComesBackByteForByte(): bool
	{
		$content = (string)file_get_contents(self::POEDIT);
		// Poedit keeps a file's Windows line endings
		$windows = str_replace("\n", "\r\n", $content);
		
		return Subject::fromString($content)->toString() === $content
			&& Subject::fromString($windows)->toString() === $windows;
	}
	
	/**
	 * msgcat writes these as it reads them
	 */
	public function obsoleteContextsAndPreviousMsgidsComeBack(): bool
	{
		$text = implode("\n", [
			...self::HEADER,
			'#, fuzzy',
			'#| msgctxt "old context"',
			'#| msgid "Old text"',
			'msgctxt "menu"',
			'msgid "New text"',
			'msgstr "Nowy tekst"',
			'',
			'#~ msgctxt "menu"',
			'#~ msgid "Gone"',
			'#~ msgstr "Nie ma"',
			'',
			'#, fuzzy',
			'#~| msgctxt "old"',
			'#~| msgid "Older"',
			'#~ msgid "Old"',
			'#~ msgstr "Stare"',
			'',
		]);
		
		return Subject::fromString($text)->toString() === $text;
	}
	
	/**
	 * A byte order mark stays, though msgcat refuses one; a comment before
	 * the header is the header's, as gettext reads it, the empty line gone
	 */
	public function keepsAByteOrderMarkAndAHeaderComment(): bool
	{
		$bom = "\xEF\xBB\xBF";
		$text = implode("\n", [
			'# A comment',
			'',
			...self::HEADER,
			'msgid "a"',
			'msgstr "b"',
			'',
		]);
		
		return Subject::fromString($bom . $text)->toString() === $bom . str_replace("# A comment\n\n", "# A comment\n", $text)
			&& Subject::fromString($text)->getHeader()?->comments === ['A comment'];
	}
	
	/**
	 * What gettext refuses or msgcat would drop is refused, naming the line
	 */
	public function refusesWhatWouldBeLost(): bool
	{
		$entry = ['msgid "a"', 'msgstr "b"', ''];
		
		return str_contains((string)self::getRefusal(implode("\n", [...self::HEADER, 'msgid "a', 'msgstr "b"', ''])), 'pl.po:8:')
			&& str_contains((string)self::getRefusal(implode("\n", [...self::HEADER, ...$entry, '# after the last entry', ''])), 'pl.po')
			&& str_contains((string)self::getRefusal(implode("\n", [...self::HEADER, ...$entry, 'msgsrt "c"', ''])), 'pl.po:11:')
			&& self::getRefusal(implode("\n", [...self::HEADER, ...$entry])) === null;
	}
	
	/**
	 * The expected file is msgmerge's output for the same .po and msgids
	 */
	public function mergeKeepsAddsRetiresAndRevives(): bool
	{
		$po = Subject::fromString(implode("\n", [
			...self::HEADER,
			'# a translator\'s note',
			'#: old.php:1',
			'msgid "Kept"',
			'msgstr "Zachowane"',
			'',
			'#: old.php:2',
			'msgid "Dropped"',
			'msgstr "Usunięte"',
			'',
			'#: old.php:3',
			'msgid "Dropped untranslated"',
			'msgstr ""',
			'',
			'#~ msgid "Back again"',
			'#~ msgstr "Znowu"',
			'',
		]));
		
		$changes = $po->merge([
			self::found('A new message long enough to be wrapped at seventy-nine columns, as Poedit does', null, ['new.php:1']),
			self::found('Back again', null, ['new.php:2']),
			self::found('Kept', null, ['new.php:3', 'other.php:7']),
			self::found('{n} new file', '{n} new files', ['new.php:4']),
		]);
		
		$msgids = static fn(array $entries): array => array_map(
			static fn(Entry $entry): string => $entry->msgid,
			$entries,
		);
		
		// the untranslated entry the code dropped is not written, as in msgcat
		return $po->toString() === implode("\n", [
			...self::HEADER,
			'#: new.php:1',
			'msgid ""',
			'"A new message long enough to be wrapped at seventy-nine columns, as Poedit "',
			'"does"',
			'msgstr ""',
			'',
			'#: new.php:2',
			'msgid "Back again"',
			'msgstr "Znowu"',
			'',
			'# a translator\'s note',
			'#: new.php:3 other.php:7',
			'msgid "Kept"',
			'msgstr "Zachowane"',
			'',
			'#: new.php:4',
			'msgid "{n} new file"',
			'msgid_plural "{n} new files"',
			'msgstr[0] ""',
			'msgstr[1] ""',
			'msgstr[2] ""',
			'',
			'#~ msgid "Dropped"',
			'#~ msgstr "Usunięte"',
			'',
		])
			&& $msgids($changes['added']) === [
				'A new message long enough to be wrapped at seventy-nine columns, as Poedit does',
				'{n} new file',
			]
			&& $msgids($changes['obsolete']) === ['Dropped', 'Dropped untranslated']
			&& $msgids($changes['revived']) === ['Back again'];
	}
	
	/**
	 * The "#." lines are the code's, as msgmerge takes them from the POT
	 */
	public function mergeTakesTheCodesComments(): bool
	{
		$po = Subject::fromString(implode("\n", [
			...self::HEADER,
			'#. translators: an old note',
			'#: old.php:1',
			'msgid "Kept"',
			'msgstr "Zachowane"',
			'',
			'#. translators: a note the code dropped',
			'#: old.php:2',
			'msgid "Without a note"',
			'msgstr "Bez uwagi"',
			'',
		]));
		$po->merge([
			self::found('Kept', null, ['new.php:1'], ['translators: a new note']),
			self::found('Without a note', null, ['new.php:2']),
		]);
		
		return $po->toString() === implode("\n", [
			...self::HEADER,
			'#. translators: a new note',
			'#: new.php:1',
			'msgid "Kept"',
			'msgstr "Zachowane"',
			'',
			'#: new.php:2',
			'msgid "Without a note"',
			'msgstr "Bez uwagi"',
			'',
		]);
	}
	
	/**
	 * A translation made for another plural is kept and marked fuzzy; one
	 * that was untranslated takes the new forms; as msgmerge does
	 */
	public function aChangedPluralMakesTheTranslationFuzzy(): bool
	{
		$po = Subject::fromString(implode("\n", [
			...self::HEADER,
			'msgid "Becomes plural"',
			'msgstr "Liczba"',
			'',
			'msgid "Was plural"',
			'msgid_plural "Were plurals"',
			'msgstr[0] "a"',
			'msgstr[1] "b"',
			'msgstr[2] "c"',
			'',
			'msgid "Plural changed"',
			'msgid_plural "Plurals changed"',
			'msgstr[0] "x"',
			'msgstr[1] "y"',
			'msgstr[2] "z"',
			'',
			'msgid "Untranslated"',
			'msgstr ""',
			'',
		]));
		$po->merge([
			self::found('Becomes plural', 'Become plurals', []),
			self::found('Was plural', null, []),
			self::found('Plural changed', 'Plurals changed now', []),
			self::found('Untranslated', 'Untranslated plurals', []),
		]);
		
		return $po->toString() === implode("\n", [
			...self::HEADER,
			'#, fuzzy',
			'msgid "Becomes plural"',
			'msgid_plural "Become plurals"',
			'msgstr[0] "Liczba"',
			'msgstr[1] "Liczba"',
			'msgstr[2] "Liczba"',
			'',
			'#, fuzzy',
			'msgid "Was plural"',
			'msgstr "a"',
			'',
			'#, fuzzy',
			'msgid "Plural changed"',
			'msgid_plural "Plurals changed now"',
			'msgstr[0] "x"',
			'msgstr[1] "y"',
			'msgstr[2] "z"',
			'',
			'msgid "Untranslated"',
			'msgid_plural "Untranslated plurals"',
			'msgstr[0] ""',
			'msgstr[1] ""',
			'msgstr[2] ""',
			'',
		]);
	}
}
