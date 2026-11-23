<?php
declare(strict_types=1);

namespace Tests\Translator;

use Ovos\Test;
use Ovos\Translator\Catalog\Exception;
use Ovos\Translator\Extractor as Subject;
use Ovos\Translator\Po;
use Ovos\Translator\Po\Entry;

use function array_keys;
use function array_map;
use function explode;
use function implode;

/**
 * Translator\Extractor - the msgids Poedit's "Update from code" finds; xgettext
 * 0.26 gives the same msgids, references and comments for the whole fixture
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Extractor extends Test
{
	protected const string FILES = __DIR__ . '/files/extractor';
	
	protected const array KEYWORDS = [
		'translate',
		'translatePlural:1,2',
		'translateContext:1c,2',
		'translatePluralContext:1c,2,3',
		'_n:1,2',
	];
	
	protected function getSubject(
		array $searchPaths = ['code', 'views'],
		array $keywords = self::KEYWORDS,
	): Subject
	{
		return new Subject(self::FILES, $searchPaths, [], $keywords);
	}
	
	/**
	 * Each entry as "context|msgid|plural" => its references
	 *
	 * @param Entry[] $entries
	 */
	protected static function describe(
		array $entries,
	): array
	{
		$described = [];
		foreach($entries as $entry)
		{
			$described[($entry->context ?? '') . '|' . $entry->msgid . '|' . ($entry->plural ?? '')] = $entry->references;
		}
		
		return $described;
	}
	
	/**
	 * A .po header naming the fixture's folder as its base path
	 */
	protected static function getPo(
		array $fields,
	): Po
	{
		$lines = [
			'msgid ""',
			'msgstr ""',
			'"Content-Type: text/plain; charset=UTF-8\n"',
		];
		foreach($fields as $field)
		{
			$lines[] = '"' . $field . '\n"';
		}
		
		return Po::fromString(implode("\n", $lines) . "\n");
	}
	
	/**
	 * Whether the extractor refuses the header's sources
	 */
	protected static function refuses(
		array $fields,
	): bool
	{
		try
		{
			Subject::fromPo(self::getPo($fields), self::FILES . '/views/de.po')->extract();
		}
		catch(Exception)
		{
			return true;
		}
		
		return false;
	}
	
	public function findsEveryKeywordInCodeAndViews(): bool
	{
		$found = self::describe($this->getSubject()->extract());
		
		return ($found['|Plain|'] ?? null) === ['code/Calls.php:3', 'code/Calls.php:13']
			&& ($found['|{n} file|{n} files'] ?? null) === ['code/Calls.php:4']
			&& ($found['verb|Open|'] ?? null) === ['code/Calls.php:5']
			&& ($found['inbox|{n} message|{n} messages'] ?? null) === ['code/Calls.php:6']
			&& ($found['|Underscore|'] ?? null) === ['code/Calls.php:7']
			&& ($found['|{n} day|{n} days'] ?? null) === ['code/Calls.php:8']
			&& ($found['|From a view|'] ?? null) === ['views/page.phtml:1']
			&& ($found['|{n} view|{n} views'] ?? null) === ['views/page.phtml:2'];
	}
	
	/**
	 * Per argument the first string outside inner brackets, whatever else
	 * stands there; a call missing a string it needs gives nothing
	 */
	public function takesArgumentsAsXgettextDoes(): bool
	{
		$found = self::describe($this->getSubject(['code/Arguments.php'])->extract());
		
		return $found === [
			'|Hello |' => ['code/Arguments.php:6'],
			'|, welcome|' => ['code/Arguments.php:7'],
			'|Many|' => ['code/Arguments.php:8'],
			'|Untitled|' => ['code/Arguments.php:9'],
			'|Cast|' => ['code/Arguments.php:11'],
			'|Named|' => ['code/Arguments.php:12'],
			'|Kept|' => ['code/Arguments.php:18'],
		];
	}
	
	public function joinsConcatenatedLiterals(): bool
	{
		$found = self::describe($this->getSubject(['code/Calls.php'])->extract());
		
		// the line of the first literal, as xgettext gives it
		return ($found['|Spread over two lines|'] ?? null) === ['code/Calls.php:10'];
	}
	
	public function readsEscapesAsXgettextDoes(): bool
	{
		$found = array_keys(self::describe($this->getSubject(['code/Escapes.php'])->extract()));
		
		return $found === [
			'|It\'s one \\ backslash, \n stays|',
			"|Tab\there, \"quoted\", AA, \$5, \\v \\u{263A} stay|",
			'|Nowdoc \t stays|',
		];
	}
	
	public function readsTheSourcesFromThePoHeader(): bool
	{
		$subject = Subject::fromPo(self::getPo([
			'X-Poedit-Basepath: ..',
			'X-Poedit-KeywordsList: translate;translatePlural:1,2',
			'X-Poedit-SearchPath-0: .',
			'X-Poedit-SearchPathExcluded-0: excluded',
		]), self::FILES . '/views/de.po');
		
		return $subject->getFiles() === [
			'code/Arguments.php',
			'code/Calls.php',
			'code/Comments.php',
			'code/Escapes.php',
			'code/Keywords.php',
			'views/page.phtml',
			'views/short.phtml',
		]
			&& array_keys($subject->getKeywords()) === [
				...array_map(
					static fn(string $keyword): string => explode(':', $keyword)[0],
					Subject::DEFAULT_KEYWORDS,
				),
				'translate',
				'translatePlural',
			]
			&& $subject->getKeywords()['translatePlural'] === [[
				'msgid' => 1,
				'plural' => 2,
				'context' => null,
				'total' => null,
			]];
	}
	
	public function excludesWithAWildcard(): bool
	{
		$subject = Subject::fromPo(self::getPo([
			'X-Poedit-Basepath: ..',
			'X-Poedit-SearchPath-0: .',
			'X-Poedit-SearchPathExcluded-0: code/*ents.php',
			'X-Poedit-SearchPathExcluded-1: *xcluded',
			'X-Poedit-SearchPathExcluded-2: code/K*',
		]), self::FILES . '/views/de.po');
		
		// files matched by name, a folder matched with what is below it
		return $subject->getFiles() === [
			'code/Calls.php',
			'code/Escapes.php',
			'views/page.phtml',
			'views/short.phtml',
		];
	}
	
	/**
	 * Merged with nothing, a .po would lose every entry: no search path, an
	 * absolute or missing one, or no file to read is refused
	 */
	public function refusesSourcesItCannotRead(): bool
	{
		return self::refuses(['X-Poedit-Basepath: ..'])
			&& self::refuses(['X-Poedit-Basepath: ..', 'X-Poedit-SearchPath-0: /code'])
			&& self::refuses(['X-Poedit-Basepath: ..', 'X-Poedit-SearchPath-0: code', 'X-Poedit-SearchPath-1: gone'])
			&& self::refuses(['X-Poedit-Basepath: ..', 'X-Poedit-SearchPath-0: empty'])
			&& self::refuses(['X-Poedit-Basepath: ..', 'X-Poedit-SearchPath-0: code']) === false;
	}
	
	public function flagsPhpFormatAsXgettextDoes(): bool
	{
		// a directive needs a known conversion; "%o" makes "50%off" one
		return Subject::isPhpFormat('%s file')
			&& Subject::isPhpFormat('a %% b')
			&& Subject::isPhpFormat('50%off')
			&& Subject::isPhpFormat('100% done') === false
			&& Subject::isPhpFormat('{n} files') === false
			&& Subject::isPhpFormat('{n} file', '%d files')
			&& Subject::isPhpFormat('%d file', '100% files') === false;
	}
	
	/**
	 * The comment right above the call with the lower-case tag; one dropped
	 * at the end of a line of other code, and once a call took it
	 */
	public function takesTheTranslatorsComment(): bool
	{
		$comments = [];
		foreach($this->getSubject(['code/Comments.php'])->extract() as $entry)
		{
			$comments[$entry->msgid] = $entry->extractedComments;
		}
		
		return $comments === [
			'Welcome' => ['translators: a greeting on the first page'],
			'Upper case tag' => [],
			'Below other code' => [],
			'First' => ['translators: only the first call of the line takes it'],
			'Second' => [],
			'Block' => ['translators: a block', 'of two lines', ''],
		];
	}
	
	/**
	 * xgettext reads "<?" as PHP, whatever short_open_tag says
	 */
	public function readsShortOpenTags(): bool
	{
		$found = self::describe($this->getSubject(['views/short.phtml'])->extract());
		
		return $found === [
			'|Short tag|' => ['views/short.phtml:1'],
			'|Long tag|' => ['views/short.phtml:3'],
		];
	}
	
	/**
	 * gettext() and the rest are keywords without the header naming them; an
	 * attribute's line is a comment to xgettext, the code in "{$...}" is code
	 */
	public function readsWhatXgettextReadsUntold(): bool
	{
		$found = self::describe($this->getSubject(['code/Keywords.php'], [])->extract());
		
		return array_keys($found) === [
			'|gettext|',
			'|ngettext one|ngettext many',
			'|dgettext|',
			'|dcgettext|',
			'|dngettext one|dngettext many',
			'|dcngettext one|dcngettext many',
		]
			&& array_keys(self::describe($this->getSubject(['code/Keywords.php'], ['translate'])->extract())) === [
				...array_keys($found),
				'|On the line after an attribute|',
				'|In an interpolation|',
			];
	}
	
	/**
	 * A keyword given twice keeps both shapes and a call takes the one that
	 * reads the most; a keyword with spaces, which xgettext skips, is refused
	 */
	public function keepsEveryShapeOfAKeyword(): bool
	{
		$subject = new Subject(self::FILES, ['code'], [], ['twice', 'twice:1,2']);
		$found = self::describe($subject->extractSource(implode("\n", [
			'<?php',
			"twice('one');",
			"twice('one file', '{n} files', \$n);",
		]), 'code/Twice.php'));
		
		try
		{
			new Subject(self::FILES, ['code'], [], ['translatePlural : 1, 2']);
			$refused = false;
		}
		catch(Exception)
		{
			$refused = true;
		}
		
		return $found === [
			'|one|' => ['code/Twice.php:2'],
			'|one file|{n} files' => ['code/Twice.php:3'],
		]
			&& $refused;
	}
}
