<?php
declare(strict_types=1);

namespace Ovos\Translator;

use Ovos\Translator\Catalog\Exception;
use Ovos\Translator\Po\Entry;
use IntlBreakIterator;
use IntlChar;

use function array_fill;
use function chr;
use function count;
use function date;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function hexdec;
use function implode;
use function in_array;
use function is_file;
use function max;
use function mb_str_split;
use function octdec;
use function ord;
use function preg_match;
use function preg_replace_callback;
use function preg_split;
use function rename;
use function rtrim;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;
use function trim;
use function unlink;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Po - a .po file read and written the way GNU msgcat writes it
 *
 * Poedit hands every file it saves to msgcat, so a file Poedit saved comes
 * back byte for byte. merge() brings the entries in line with the msgids
 * Extractor found in the code, as msgmerge does without fuzzy matching.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Po
{
	/**
	 * msgcat's default line width, which Poedit uses too
	 */
	public const int WIDTH = 79;
	
	/**
	 * Characters msgcat writes as a backslash and a letter, in the order of
	 * ESCAPE_LETTERS
	 */
	protected const string ESCAPES = "\x07\x08\x0C\n\r\t\x0B";
	
	protected const string ESCAPE_LETTERS = 'abfnrtv';
	
	protected const string BOM = "\xEF\xBB\xBF";
	
	/**
	 * Every entry in file order, the header included
	 *
	 * @var Entry[]
	 */
	protected array $entries = [];
	
	/**
	 * Line ending of the file read, kept on write as Poedit does
	 */
	protected string $eol = "\n";
	
	/**
	 * A UTF-8 byte order mark the file started with, kept on write
	 */
	protected string $bom = '';
	
	protected static ?IntlBreakIterator $lineBreaks = null;
	
	public static function fromFile(
		string $path,
	): static
	{
		$content = is_file($path)
			? file_get_contents($path)
			: false;
		if($content === false)
		{
			throw new Exception(sprintf('Cannot read "%s".', $path));
		}
		
		return static::fromString($content, $path);
	}
	
	/**
	 * Reads a .po as gettext does: a comment belongs to the entry after it,
	 * empty lines or not
	 *
	 * @throws Exception for what gettext refuses, a line it cannot read, an
	 *     unclosed quote, a msgid given twice in the file, "#~" or not, and
	 *     comments after the last entry, which msgcat drops
	 */
	public static function fromString(
		string $content,
		string $name = 'the .po',
	): static
	{
		$po = new static;
		
		if(str_starts_with($content, self::BOM))
		{
			$po->bom = self::BOM;
			$content = substr($content, strlen(self::BOM));
		}
		
		$end = strpos($content, "\n");
		if($end !== false
			&& $end > 0
			&& $content[$end - 1] === "\r")
		{
			$po->eol = "\r\n";
		}
		
		$entry = null;
		$field = null;
		$hasMsgid = false;
		$hasMsgstr = false;
		
		foreach(explode("\n", str_replace("\r\n", "\n", $content)) as $number => $raw)
		{
			$where = $name . ':' . ($number + 1);
			$line = rtrim($raw, " \t");
			
			// an empty line ends an entry, but not the comments before one
			if($line === '')
			{
				if($hasMsgid)
				{
					$po->add($entry, $where);
					$entry = null;
					$field = null;
					$hasMsgid = false;
					$hasMsgstr = false;
				}
				
				continue;
			}
			
			$obsolete = false;
			$previous = false;
			if(str_starts_with($line, '#~'))
			{
				$obsolete = true;
				$line = trim(substr($line, 2));
				if(str_starts_with($line, '|'))
				{
					$previous = true;
					$line = trim(substr($line, 1));
				}
			}
			else if(str_starts_with($line, '#|'))
			{
				$previous = true;
				$line = trim(substr($line, 2));
			}
			else if($line[0] === '#')
			{
				// a comment after a msgstr opens the next entry
				if($hasMsgstr)
				{
					$po->add($entry, $where);
					$entry = null;
					$hasMsgid = false;
					$hasMsgstr = false;
				}
				
				// a comment keeps its trailing spaces, as in msgcat
				$entry ??= $po->open();
				$field = null;
				self::readComment($entry, $raw);
				
				continue;
			}
			
			if($line === '')
			{
				continue;
			}
			
			if($line[0] === '"')
			{
				if($entry === null || $field === null)
				{
					throw new Exception(sprintf('%s: a string continues no msgid or msgstr.', $where));
				}
				
				self::append($entry, $field, self::unquote($line, $where));
				
				continue;
			}
			
			$space = strpos($line, ' ');
			$keyword = $space === false
				? $line
				: substr($line, 0, $space);
			if(preg_match('/^(msgctxt|msgid|msgid_plural|msgstr|msgstr\[\d+])$/', $keyword) === 0)
			{
				throw new Exception(sprintf('%s: "%s" is not a line of a .po.', $where, $line));
			}
			
			$value = self::unquote(trim(substr($line, strlen($keyword))), $where);
			
			// a msgctxt, msgid or "#|" line after a msgstr opens the next entry
			if($hasMsgstr
				&& ($previous || $keyword === 'msgctxt' || $keyword === 'msgid'))
			{
				$po->add($entry, $where);
				$entry = null;
				$hasMsgid = false;
				$hasMsgstr = false;
			}
			
			$entry ??= $po->open();
			$entry->obsolete = $entry->obsolete || $obsolete;
			$field = $previous
				? 'previous ' . $keyword
				: $keyword;
			self::append($entry, $field, $value);
			
			$hasMsgid = $hasMsgid || ($previous === false && $keyword === 'msgid');
			$hasMsgstr = $hasMsgstr || str_starts_with($keyword, 'msgstr');
		}
		
		if($entry !== null
			&& $hasMsgid === false)
		{
			throw new Exception(sprintf('%s: comments after the last entry, which msgcat would drop.', $name));
		}
		
		$po->add($entry, $name);
		$po->checkDuplicates($name);
		
		return $po;
	}
	
	/**
	 * A new entry while reading, its translations filled by the msgstr lines
	 */
	protected function open(): Entry
	{
		$entry = new Entry;
		$entry->translations = [];
		
		return $entry;
	}
	
	protected function add(
		?Entry $entry,
		string $where,
	): void
	{
		if($entry === null)
		{
			return;
		}
		
		if($entry->translations === [])
		{
			throw new Exception(sprintf('%s: an entry without a msgstr.', $where));
		}
		
		$this->entries[] = $entry;
	}
	
	/**
	 * gettext refuses a msgid given twice with the same context, also when
	 * one or both are "#~"
	 */
	protected function checkDuplicates(
		string $name,
	): void
	{
		$seen = [];
		foreach($this->entries as $entry)
		{
			$key = $entry->getKey();
			if(isset($seen[$key]))
			{
				throw new Exception(sprintf('%s: the msgid "%s" is there twice.', $name, $entry->msgid));
			}
			
			$seen[$key] = true;
		}
	}
	
	protected static function readComment(
		Entry $entry,
		string $line,
	): void
	{
		$kind = $line[1] ?? '';
		$text = substr($line, 2);
		
		match($kind)
		{
			'.' => $entry->extractedComments[] = str_starts_with($text, ' ')
				? substr($text, 1)
				: $text,
			':' => self::readReferences($entry, $text),
			',', '!' => self::readFlags($entry, $text),
			// a plain comment loses the one space after the "#", as in gettext
			' ' => $entry->comments[] = $text,
			default => $entry->comments[] = substr($line, 1),
		};
	}
	
	protected static function readReferences(
		Entry $entry,
		string $text,
	): void
	{
		foreach(preg_split('/\s+/', trim($text), flags: PREG_SPLIT_NO_EMPTY) as $reference)
		{
			$entry->references[] = $reference;
		}
	}
	
	protected static function readFlags(
		Entry $entry,
		string $text,
	): void
	{
		foreach(explode(',', $text) as $flag)
		{
			$flag = trim($flag);
			if($flag !== '')
			{
				$entry->flags[] = $flag;
			}
		}
	}
	
	protected static function append(
		Entry $entry,
		string $field,
		string $value,
	): void
	{
		if(preg_match('/^msgstr\[(\d+)]$/', $field, $match))
		{
			$index = (int)$match[1];
			$entry->translations[$index] = ($entry->translations[$index] ?? '') . $value;
			
			return;
		}
		
		match($field)
		{
			'msgctxt' => $entry->context = ($entry->context ?? '') . $value,
			'msgid' => $entry->msgid .= $value,
			'msgid_plural' => $entry->plural = ($entry->plural ?? '') . $value,
			'msgstr' => $entry->translations[0] = ($entry->translations[0] ?? '') . $value,
			'previous msgctxt' => $entry->previousContext = ($entry->previousContext ?? '') . $value,
			'previous msgid' => $entry->previousMsgid = ($entry->previousMsgid ?? '') . $value,
			'previous msgid_plural' => $entry->previousPlural = ($entry->previousPlural ?? '') . $value,
			default => null,
		};
	}
	
	/**
	 * The text of a quoted PO string, its C escapes resolved
	 */
	protected static function unquote(
		string $quoted,
		string $where,
	): string
	{
		if(preg_match('/^"((?:[^"\\\\]|\\\\.)*)"$/s', $quoted, $match) === 0)
		{
			throw new Exception(sprintf('%s: a string without its closing quote.', $where));
		}
		
		return preg_replace_callback(
			'/\\\\(?:([0-7]{1,3})|x([0-9A-Fa-f]+)|(.))/s',
			static function(array $match): string
			{
				if(($match[1] ?? '') !== '')
				{
					return chr(octdec($match[1]) & 0xFF);
				}
				
				if(($match[2] ?? '') !== '')
				{
					return chr(hexdec($match[2]) & 0xFF);
				}
				
				$letter = strpos(self::ESCAPE_LETTERS, $match[3]);
				
				return $letter === false
					? $match[3]
					: self::ESCAPES[$letter];
			},
			$match[1],
		);
	}
	
	/**
	 * Every entry in file order: the header, the messages and the "#~" ones
	 *
	 * @return Entry[]
	 */
	public function getEntries(): array
	{
		return $this->entries;
	}
	
	/**
	 * The entries the code uses: neither the header nor "#~"
	 *
	 * @return Entry[]
	 */
	public function getMessages(): array
	{
		$messages = [];
		foreach($this->entries as $entry)
		{
			if($entry->obsolete === false
				&& $entry->isHeader() === false)
			{
				$messages[] = $entry;
			}
		}
		
		return $messages;
	}
	
	public function getHeader(): ?Entry
	{
		foreach($this->entries as $entry)
		{
			if($entry->obsolete === false
				&& $entry->isHeader())
			{
				return $entry;
			}
		}
		
		return null;
	}
	
	/**
	 * The header's fields, name => value, the first of a name given twice
	 *
	 * @return array<string, string>
	 */
	public function getHeaderFields(): array
	{
		$fields = [];
		foreach(explode("\n", $this->getHeader()?->translations[0] ?? '') as $line)
		{
			$colon = strpos($line, ':');
			if($colon !== false)
			{
				$fields[substr($line, 0, $colon)] ??= trim(substr($line, $colon + 1));
			}
		}
		
		return $fields;
	}
	
	public function getHeaderField(
		string $name,
	): ?string
	{
		return $this->getHeaderFields()[$name] ?? null;
	}
	
	/**
	 * Replaces the value of a header field the file has; a missing field is
	 * not added
	 */
	public function setHeaderField(
		string $name,
		string $value,
	): bool
	{
		$header = $this->getHeader();
		if($header === null)
		{
			return false;
		}
		
		$lines = explode("\n", $header->translations[0]);
		foreach($lines as $index => $line)
		{
			if(str_starts_with($line, $name . ':'))
			{
				$lines[$index] = $name . ': ' . $value;
				$header->translations[0] = implode("\n", $lines);
				
				return true;
			}
		}
		
		return false;
	}
	
	/**
	 * The number of plural forms the header's Plural-Forms names, two
	 * without one, as msgmerge counts
	 */
	public function getPluralCount(): int
	{
		$forms = $this->getHeaderField('Plural-Forms') ?? '';
		
		return preg_match('/nplurals\s*=\s*(\d+)/', $forms, $match)
			? max(1, (int)$match[1])
			: 2;
	}
	
	/**
	 * Brings the entries in line with the msgids found in the code, in the
	 * code's order: a translation is kept, a new msgid comes in untranslated,
	 * one the code dropped becomes "#~" and a "#~" one the code uses again
	 * comes back. An entry matches only by its exact context and msgid. Its
	 * references, "#." lines and format flags are the code's; its fuzzy flag
	 * and the translator's comments stay. When only its plural changed, a translation is marked
	 * fuzzy, as msgmerge does.
	 *
	 * @param Entry[] $found what Extractor found
	 * @return array{added: Entry[], obsolete: Entry[], revived: Entry[]}
	 */
	public function merge(
		array $found,
	): array
	{
		$forms = $this->getPluralCount();
		$header = $this->getHeader();
		
		$known = [];
		foreach($this->entries as $entry)
		{
			if($entry !== $header)
			{
				$known[$entry->getKey()] ??= $entry;
			}
		}
		
		$changes = [
			'added' => [],
			'obsolete' => [],
			'revived' => [],
		];
		$entries = $header === null
			? []
			: [$header];
		$used = [];
		
		foreach($found as $code)
		{
			$key = $code->getKey();
			if(isset($used[$key]))
			{
				continue;
			}
			
			$entry = $known[$key] ?? null;
			if($entry === null)
			{
				$entry = new Entry($code->msgid, $code->plural, $code->context);
				$entry->translations = array_fill(0, $code->plural === null ? 1 : $forms, '');
				$changes['added'][] = $entry;
			}
			else
			{
				if($entry->obsolete)
				{
					$entry->obsolete = false;
					$changes['revived'][] = $entry;
				}
				
				$this->takePlural($entry, $code->plural, $forms);
			}
			
			$entry->references = $code->references;
			$entry->extractedComments = $code->extractedComments;
			$entry->flags = $entry->isFuzzy()
				? [Entry::FLAG_FUZZY, ...$code->flags]
				: $code->flags;
				
			$used[$key] = true;
			$entries[] = $entry;
		}
		
		// what the code no longer uses goes to the end, in file order
		foreach($this->entries as $entry)
		{
			if($entry === $header
				|| isset($used[$entry->getKey()]))
			{
				continue;
			}
			
			if($entry->obsolete === false)
			{
				// an untranslated one is left out on write, as msgcat does
				$entry->obsolete = true;
				$entry->references = [];
				$entry->extractedComments = [];
				$changes['obsolete'][] = $entry;
			}
			
			$entries[] = $entry;
		}
		
		$this->entries = $entries;
		
		return $changes;
	}
	
	/**
	 * Gives an entry the code's plural msgid, reshaping its translations
	 */
	protected function takePlural(
		Entry $entry,
		?string $plural,
		int $forms,
	): void
	{
		if($entry->plural === $plural)
		{
			return;
		}
		
		// the translation was made for other msgids
		if($entry->isTranslated())
		{
			$entry->setFuzzy(true);
		}
		
		if($plural === null)
		{
			$entry->translations = [$entry->translations[0]];
		}
		else if($entry->plural === null)
		{
			$entry->translations = array_fill(0, $forms, $entry->translations[0]);
		}
		
		$entry->plural = $plural;
	}
	
	/**
	 * Sets the dates Poedit sets on an update from the code, where the header
	 * has them: an empty PO-Revision-Date stays empty, as in Poedit
	 */
	public function touch(): static
	{
		$now = date('Y-m-d H:iO');
		
		if($this->getHeaderField('POT-Creation-Date') !== null)
		{
			$this->setHeaderField('POT-Creation-Date', $now);
		}
		
		if(($this->getHeaderField('PO-Revision-Date') ?? '') !== '')
		{
			$this->setHeaderField('PO-Revision-Date', $now);
		}
		
		return $this;
	}
	
	public function save(
		string $path,
	): void
	{
		// a reader never sees a half-written file
		$temporary = $path . '.tmp';
		if(file_put_contents($temporary, $this->toString()) === false
			|| rename($temporary, $path) === false)
		{
			if(is_file($temporary))
			{
				unlink($temporary);
			}
			
			throw new Exception(sprintf('Cannot write "%s".', $path));
		}
	}
	
	/**
	 * The file as msgcat writes it: the entries in order, then the "#~" ones
	 */
	public function toString(): string
	{
		$blocks = [];
		foreach($this->entries as $entry)
		{
			if($entry->obsolete === false)
			{
				$blocks[] = $this->format($entry);
			}
		}
		
		foreach($this->entries as $entry)
		{
			// msgcat drops an untranslated "#~" entry
			if($entry->obsolete
				&& $entry->isTranslated())
			{
				$blocks[] = $this->format($entry);
			}
		}
		
		$content = implode("\n", $blocks);
		
		return $this->bom . ($this->eol === "\n"
			? $content
			: str_replace("\n", $this->eol, $content));
	}
	
	public function __toString(): string
	{
		return $this->toString();
	}
	
	protected function format(
		Entry $entry,
	): string
	{
		$text = '';
		foreach($entry->comments as $comment)
		{
			$text .= $comment === ''
				? "#\n"
				: '# ' . $comment . "\n";
		}
		
		foreach($entry->extractedComments as $comment)
		{
			$text .= $comment === ''
				? "#.\n"
				: '#. ' . $comment . "\n";
		}
		
		$text .= $this->formatReferences($entry->references);
		$text .= $this->formatFlags($entry);
		
		$previous = $entry->obsolete
			? '#~| '
			: '#| ';
		if($entry->previousContext !== null)
		{
			$text .= $this->wrap($previous, 'msgctxt', $entry->previousContext);
		}
		
		if($entry->previousMsgid !== null)
		{
			$text .= $this->wrap($previous, 'msgid', $entry->previousMsgid);
		}
		
		if($entry->previousPlural !== null)
		{
			$text .= $this->wrap($previous, 'msgid_plural', $entry->previousPlural);
		}
		
		$prefix = $entry->obsolete
			? '#~ '
			: '';
		if($entry->context !== null)
		{
			$text .= $this->wrap($prefix, 'msgctxt', $entry->context);
		}
		
		$text .= $this->wrap($prefix, 'msgid', $entry->msgid);
		
		if($entry->plural === null)
		{
			return $text . $this->wrap($prefix, 'msgstr', $entry->translations[0] ?? '');
		}
		
		$text .= $this->wrap($prefix, 'msgid_plural', $entry->plural);
		foreach($entry->translations as $index => $translation)
		{
			$text .= $this->wrap($prefix, 'msgstr[' . $index . ']', $translation);
		}
		
		return $text;
	}
	
	/**
	 * "#:" lines, a new one before a reference that would pass the width
	 */
	protected function formatReferences(
		array $references,
	): string
	{
		if($references === [])
		{
			return '';
		}
		
		$text = '#:';
		$column = 2;
		foreach($references as $reference)
		{
			$length = strlen($reference) + 1;
			if($column > 2
				&& $column + $length > self::WIDTH)
			{
				$text .= "\n#:";
				$column = 2;
			}
			
			$text .= ' ' . $reference;
			$column += $length;
		}
		
		return $text . "\n";
	}
	
	/**
	 * The "#," line: fuzzy first and only on a translated entry
	 */
	protected function formatFlags(
		Entry $entry,
	): string
	{
		$flags = [];
		if($entry->isFuzzy()
			&& $entry->isTranslated())
		{
			$flags[] = Entry::FLAG_FUZZY;
		}
		
		foreach($entry->flags as $flag)
		{
			if($flag !== Entry::FLAG_FUZZY)
			{
				$flags[] = $flag;
			}
		}
		
		return $flags === []
			? ''
			: '#, ' . implode(', ', $flags) . "\n";
	}
	
	/**
	 * One keyword and its string as msgcat writes it: one line per "\n" of the
	 * text, each wrapped where Unicode allows a line break so that no line
	 * passes WIDTH columns where it can be helped. A text that is longer than
	 * one line, or holds a "\n" before its end, starts on the line after an
	 * empty one.
	 */
	protected function wrap(
		string $prefix,
		string $name,
		string $value,
	): string
	{
		// room left between the quotes of a line after a break
		$width = self::WIDTH - 2 - strlen($prefix);
		$first = strlen($name) + 1;
		$length = strlen($value);
		$offset = 0;
		$firstLine = true;
		$text = '';
		
		do
		{
			$end = strpos($value, "\n", $offset);
			$end = $end === false
				? $length
				: $end + 1;
			[$characters, $fixed] = $this->escape(substr($value, $offset, $end - $offset));
			$offset = $end;
			
			$allowed = $this->getBreakOpportunities($characters, $fixed);
			$breaks = $this->breakLines($characters, $allowed, $width, $firstLine ? $first : 0);
			
			if($firstLine
				&& $characters !== []
				&& ($offset < $length
					|| $first > $width
					|| in_array(true, $breaks, true)))
			{
				$text .= $prefix . $name . " \"\"\n";
				$firstLine = false;
				$breaks = $this->breakLines($characters, $allowed, $width, 0);
			}
			
			$text .= $prefix . ($firstLine ? $name . ' ' : '') . '"';
			$firstLine = false;
			foreach($characters as $index => $character)
			{
				if($breaks[$index])
				{
					$text .= "\"\n" . $prefix . '"';
				}
				
				$text .= $character;
			}
			
			$text .= "\"\n";
		}
		while($offset < $length);
		
		return $text;
	}
	
	/**
	 * The characters of a text as written between quotes, and the positions
	 * no line may start at: inside an escape sequence, and before the "\n"
	 * that ends the text
	 *
	 * @return array{0: string[], 1: array<int, true>}
	 */
	protected function escape(
		string $text,
	): array
	{
		$characters = [];
		$fixed = [];
		
		foreach(mb_str_split($text) as $character)
		{
			$escape = strlen($character) === 1
				? strpos(self::ESCAPES, $character)
				: false;
				
			if($escape === false
				&& $character !== '\\'
				&& $character !== '"')
			{
				$characters[] = $character;
				
				continue;
			}
			
			$characters[] = '\\';
			$fixed[count($characters)] = true;
			$characters[] = $escape === false
				? $character
				: self::ESCAPE_LETTERS[$escape];
		}
		
		if(str_ends_with($text, "\n"))
		{
			$fixed[count($characters) - 2] = true;
		}
		
		return [$characters, $fixed];
	}
	
	/**
	 * Where a line may break before a character, by Unicode's line breaking
	 * rules over the escaped text, as gettext breaks it
	 *
	 * @param string[] $characters
	 * @param array<int, true> $fixed
	 * @return bool[]
	 */
	protected function getBreakOpportunities(
		array $characters,
		array $fixed,
	): array
	{
		if($characters === [])
		{
			return [];
		}
		
		$allowed = array_fill(0, count($characters), false);
		
		$indexes = [];
		$byte = 0;
		foreach($characters as $index => $character)
		{
			$indexes[$byte] = $index;
			$byte += strlen($character);
		}
		
		self::$lineBreaks ??= IntlBreakIterator::createLineInstance('en');
		self::$lineBreaks->setText(implode('', $characters));
		foreach(self::$lineBreaks as $boundary)
		{
			if($boundary > 0
				&& isset($indexes[$boundary]))
			{
				$allowed[$indexes[$boundary]] = true;
			}
		}
		
		// libunistring, unlike ICU, breaks between a "." "," ":" or ";" and a
		// "<", such as the start of a tag after a sentence
		foreach($characters as $index => $character)
		{
			if($character === '<'
				&& $index > 0
				&& IntlChar::getIntPropertyValue($characters[$index - 1], IntlChar::PROPERTY_LINE_BREAK) === IntlChar::LB_INFIX_NUMERIC)
			{
				$allowed[$index] = true;
			}
		}
		
		foreach($fixed as $index => $true)
		{
			$allowed[$index] = false;
		}
		
		return $allowed;
	}
	
	/**
	 * Picks the breaks the way libunistring fills lines: a piece runs from
	 * one opportunity to the next, and the line breaks before the piece that
	 * would pass the width. A piece wider than a line stays whole.
	 *
	 * @param string[] $characters
	 * @param bool[] $allowed
	 * @return bool[]
	 */
	protected function breakLines(
		array $characters,
		array $allowed,
		int $width,
		int $column,
	): array
	{
		$breaks = array_fill(0, count($characters), false);
		$pieceStart = null;
		$pieceWidth = 0;
		
		foreach($characters as $index => $character)
		{
			if($allowed[$index])
			{
				if($pieceStart !== null
					&& $column + $pieceWidth > $width)
				{
					$breaks[$pieceStart] = true;
					$column = 0;
				}
				
				$pieceStart = $index;
				$column += $pieceWidth;
				$pieceWidth = 0;
			}
			
			$pieceWidth += self::getColumns($character);
		}
		
		if($pieceStart !== null
			&& $column + $pieceWidth > $width)
		{
			$breaks[$pieceStart] = true;
		}
		
		return $breaks;
	}
	
	/**
	 * Screen columns of a character: none for a mark or a control, two for a
	 * wide East Asian one
	 */
	protected static function getColumns(
		string $character,
	): int
	{
		if(strlen($character) === 1)
		{
			$code = ord($character);
			
			return $code < 0x20 || $code === 0x7F
				? 0
				: 1;
		}
		
		$code = IntlChar::ord($character);
		if($code === null
			|| $code === 0xAD)
		{
			return 1;
		}
		
		if(in_array(IntlChar::charType($code), [
			IntlChar::CHAR_CATEGORY_NON_SPACING_MARK,
			IntlChar::CHAR_CATEGORY_ENCLOSING_MARK,
			IntlChar::CHAR_CATEGORY_FORMAT_CHAR,
			IntlChar::CHAR_CATEGORY_CONTROL_CHAR,
		], true))
		{
			return 0;
		}
		
		$width = IntlChar::getIntPropertyValue($code, IntlChar::PROPERTY_EAST_ASIAN_WIDTH);
		
		return $width === IntlChar::EA_WIDE || $width === IntlChar::EA_FULLWIDTH
			? 2
			: 1;
	}
}
