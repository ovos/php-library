<?php
declare(strict_types=1);

namespace Ovos\Translator;

use Ovos\Translator\Catalog\Exception;
use Ovos\Translator\Po\Entry;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_key_last;
use function array_pop;
use function chr;
use function count;
use function dirname;
use function explode;
use function file_get_contents;
use function fnmatch;
use function hexdec;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function ksort;
use function ltrim;
use function octdec;
use function preg_match;
use function preg_replace;
use function preg_replace_callback;
use function rtrim;
use function sort;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strpos;
use function strspn;
use function substr;
use function substr_count;
use function substr_replace;
use function token_get_all;
use function usort;

use const PREG_OFFSET_CAPTURE;
use const SORT_STRING;
use const T_CLOSE_TAG;
use const T_COMMENT;
use const T_CONSTANT_ENCAPSED_STRING;
use const T_CURLY_OPEN;
use const T_DOC_COMMENT;
use const T_DOLLAR_OPEN_CURLY_BRACES;
use const T_ATTRIBUTE;
use const T_ENCAPSED_AND_WHITESPACE;
use const T_END_HEREDOC;
use const T_INLINE_HTML;
use const T_OPEN_TAG;
use const T_OPEN_TAG_WITH_ECHO;
use const T_START_HEREDOC;
use const T_WHITESPACE;

/**
 * Extractor - finds the msgids a project's code translates exactly as
 * Poedit's "Update from code" finds them with xgettext 0.26
 *
 * The files are the .php and .phtml ones under the search paths, read in
 * the byte order of their paths. A call of a keyword takes, for each
 * argument it needs, the first string literal standing in that argument
 * outside any inner parentheses or brackets, whatever else stands there:
 * 'Hello ' . $name gives "Hello ", $a ? 'x' : 'y' gives "x", and a named
 * argument counts by its position. Literals joined with "." are one string,
 * as far as xgettext joins them. A literal reads as xgettext reads it, which
 * is not always as PHP does: in double quotes "\v", "\e", "\f" and "\u{...}"
 * stay as written. The comment right above a call whose text holds
 * COMMENT_TAG becomes the entry's "#." lines.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Extractor
{
	public const array EXTENSIONS = ['php', 'phtml'];
	
	/**
	 * The keywords xgettext knows for PHP without being told
	 */
	public const array DEFAULT_KEYWORDS = [
		'_',
		'gettext',
		'dgettext:2',
		'dcgettext:2',
		'ngettext:1,2',
		'dngettext:2,3',
		'dcngettext:2,3',
	];
	
	/**
	 * Poedit passes --add-comments=TRANSLATORS: and then
	 * --add-comments=translators:, and xgettext keeps only the last
	 */
	public const string COMMENT_TAG = 'translators:';
	
	public const string FLAG_PHP_FORMAT = 'php-format';
	
	/**
	 * A PHP format directive as xgettext's heuristic accepts it
	 */
	protected const string DIRECTIVE = "/%(?:%|(?:0*[1-9][0-9]*\\$)?(?:[0-]|'.)*[0-9]*(?:\\.[0-9]+)?l?[bcdeEfFgGhHosuxX])/s";
	
	/**
	 * Last characters of the token before a string that keep the string
	 * from starting a "." chain in xgettext: a dot, an arithmetic or unary
	 * operator, a closing parenthesis
	 */
	protected const array UNCHAINED_AFTER = ['.', '*', '/', '+', '-', '!', '~', '@', ')'];
	
	/**
	 * A name xgettext reads as a symbol: a variable's without its "$", a
	 * namespaced one's last part
	 */
	protected const string NAME = '/^\$?\\\\?(?:[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*\\\\)*([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)$/';
	
	/**
	 * Keyword name => its call shapes: the 1-based positions of the msgid,
	 * the plural and the context, and the number of arguments a call needs
	 *
	 * @var array<string, list<array{msgid: int, plural: ?int, context: ?int, total: ?int}>>
	 */
	protected array $keywords = [];
	
	/**
	 * @param string[] $searchPaths files or folders, relative to the base path
	 * @param string[] $excludedPaths relative to the base path, "*" allowed
	 * @param string[] $keywords in xgettext's syntax: "name", "name:1,2",
	 *     "name:1c,2", "name:1c,2,3", "name:1,2t"; given beside DEFAULT_KEYWORDS
	 */
	public function __construct(
		protected string $basePath,
		protected array $searchPaths,
		protected array $excludedPaths = [],
		array $keywords = [],
	)
	{
		$this->basePath = rtrim(str_replace('\\', '/', $basePath), '/');
		
		foreach([...self::DEFAULT_KEYWORDS, ...$keywords] as $keyword)
		{
			$this->addKeyword($keyword);
		}
	}
	
	/**
	 * An extractor set up as Poedit reads a .po header: X-Poedit-Basepath
	 * relative to the file's folder unless absolute, the search paths and the
	 * excluded ones relative to that, and the X-Poedit-KeywordsList
	 */
	public static function fromPo(
		Po $po,
		string $poPath,
	): static
	{
		$fields = $po->getHeaderFields();
		$base = $fields['X-Poedit-Basepath'] ?? '.';
		if(preg_match('~^([A-Za-z]:)?[/\\\\]~', $base) === 0)
		{
			$base = dirname($poPath) . '/' . $base;
		}
		
		$searchPaths = [];
		$excludedPaths = [];
		foreach($fields as $name => $value)
		{
			if(preg_match('/^X-Poedit-SearchPath-(\d+)$/', $name, $match))
			{
				$searchPaths[(int)$match[1]] = $value;
			}
			else if(preg_match('/^X-Poedit-SearchPathExcluded-(\d+)$/', $name, $match))
			{
				$excludedPaths[(int)$match[1]] = $value;
			}
		}
		
		ksort($searchPaths);
		ksort($excludedPaths);
		
		// each keyword as written: xgettext takes none with white space in it
		$keywords = [];
		foreach(explode(';', $fields['X-Poedit-KeywordsList'] ?? '') as $keyword)
		{
			if($keyword !== '')
			{
				$keywords[] = $keyword;
			}
		}
		
		return new static(
			$base,
			[...$searchPaths],
			[...$excludedPaths],
			$keywords,
		);
	}
	
	/**
	 * Adds a call shape in xgettext's syntax. A name given again keeps both
	 * shapes; a call takes the one that reads the most: context and plural,
	 * context, plural, msgid alone, the first given within each.
	 *
	 * @throws Exception for a keyword xgettext does not read, such as one with
	 *     white space: xgettext skips it without a word
	 */
	public function addKeyword(
		string $keyword,
	): static
	{
		if(preg_match('/^([^\s:,]+)(?::(\S+))?$/', $keyword, $match) === 0)
		{
			throw new Exception(sprintf('The keyword "%s" is not in xgettext\'s syntax, which xgettext skips; write it as "name" or "name:1,2" without spaces.', $keyword));
		}
		
		$shape = [
			'msgid' => null,
			'plural' => null,
			'context' => null,
			'total' => null,
		];
		
		foreach(($match[2] ?? '') === '' ? [] : explode(',', $match[2]) as $part)
		{
			if(preg_match('/^([1-9]\d*)([ct]?)$/', $part, $position) === 0)
			{
				throw new Exception(sprintf('The keyword "%s" is not in xgettext\'s syntax.', $keyword));
			}
			
			$number = (int)$position[1];
			if($position[2] === 'c')
			{
				$shape['context'] = $number;
			}
			else if($position[2] === 't')
			{
				$shape['total'] = $number;
			}
			else if($shape['msgid'] === null)
			{
				$shape['msgid'] = $number;
			}
			else
			{
				$shape['plural'] = $number;
			}
		}
		
		$shape['msgid'] ??= 1;
		if(in_array($shape, $this->keywords[$match[1]] ?? [], true) === false)
		{
			$this->keywords[$match[1]][] = $shape;
		}
		
		return $this;
	}
	
	/**
	 * @return array<string, list<array{msgid: int, plural: ?int, context: ?int, total: ?int}>>
	 */
	public function getKeywords(): array
	{
		return $this->keywords;
	}
	
	/**
	 * The files to read, relative to the base path with "/", in byte order
	 * of their paths as Poedit hands them to xgettext
	 *
	 * @return string[]
	 * @throws Exception when the header names no search path, an absolute or
	 *     missing one, or no file to read: merged with nothing, every entry
	 *     of the .po would be retired
	 */
	public function getFiles(): array
	{
		if($this->searchPaths === [])
		{
			throw new Exception('The header has no X-Poedit-SearchPath-0: no code to read.');
		}
		
		$files = [];
		foreach($this->searchPaths as $given)
		{
			$searchPath = self::normalize($given);
			if(preg_match('~^([A-Za-z]:)?/~', $searchPath))
			{
				throw new Exception(sprintf('The search path "%s" is absolute; give it relative to X-Poedit-Basepath.', $given));
			}
			
			$absolute = $searchPath === '.'
				? $this->basePath
				: $this->basePath . '/' . $searchPath;
				
			if(is_file($absolute))
			{
				$files[$searchPath] = true;
				
				continue;
			}
			
			if(is_dir($absolute) === false)
			{
				throw new Exception(sprintf('The search path "%s" does not exist in "%s".', $given, $this->basePath));
			}
			
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS),
			);
			foreach($iterator as $file)
			{
				/**
				 * @var SplFileInfo $file
				 */
				if($file->isFile() === false
					|| in_array($file->getExtension(), self::EXTENSIONS, true) === false)
				{
					continue;
				}
				
				$relative = str_replace('\\', '/', substr($file->getPathname(), strlen($absolute) + 1));
				$path = $searchPath === '.'
					? $relative
					: $searchPath . '/' . $relative;
					
				// hidden files and folders are left out, as Poedit leaves them
				if(preg_match('~(^|/)\.~', $relative) === 0)
				{
					$files[$path] = true;
				}
			}
		}
		
		$paths = [];
		foreach($files as $path => $true)
		{
			if($this->isExcluded((string)$path) === false)
			{
				$paths[] = (string)$path;
			}
		}
		
		if($paths === [])
		{
			throw new Exception(sprintf('No .php or .phtml file under the search paths of "%s".', $this->basePath));
		}
		
		sort($paths, SORT_STRING);
		
		return $paths;
	}
	
	/**
	 * An excluded path leaves out itself and what is below it; with "*" in
	 * it, it is matched against the file and each folder above it
	 */
	protected function isExcluded(
		string $path,
	): bool
	{
		foreach($this->excludedPaths as $excluded)
		{
			$excluded = self::normalize($excluded);
			if($path === $excluded
				|| str_starts_with($path, $excluded . '/'))
			{
				return true;
			}
			
			if(str_contains($excluded, '*') === false)
			{
				continue;
			}
			
			for($part = $path; $part !== '.' && $part !== ''; $part = dirname($part))
			{
				if(fnmatch($excluded, $part))
				{
					return true;
				}
			}
		}
		
		return false;
	}
	
	protected static function normalize(
		string $path,
	): string
	{
		$path = rtrim(str_replace('\\', '/', $path), '/');
		while(str_starts_with($path, './'))
		{
			$path = substr($path, 2);
		}
		
		return $path === ''
			? '.'
			: $path;
	}
	
	/**
	 * Every msgid of every file, keyed by context and msgid, in the order
	 * xgettext takes them, each with its references, its "#." lines and its
	 * php-format flag
	 *
	 * @return array<string, Entry>
	 * @throws Exception see getFiles()
	 */
	public function extract(): array
	{
		$entries = [];
		foreach($this->getFiles() as $path)
		{
			$code = file_get_contents($this->basePath . '/' . $path);
			if($code === false)
			{
				throw new Exception(sprintf('Cannot read "%s".', $path));
			}
			
			$this->extractCode($code, $path, $entries);
		}
		
		return $this->finish($entries);
	}
	
	/**
	 * The msgids of one piece of code, its references naming the given path
	 *
	 * @return array<string, Entry>
	 */
	public function extractSource(
		string $code,
		string $path,
	): array
	{
		$entries = [];
		$this->extractCode($code, $path, $entries);
		
		return $this->finish($entries);
	}
	
	/**
	 * @param array<string, Entry> $entries
	 */
	protected function extractCode(
		string $code,
		string $path,
		array &$entries,
	): void
	{
		$events = self::getEvents(self::openShortTags($code));
		
		$tokens = [];
		foreach($events as $index => $event)
		{
			if($event['kind'] === 'token')
			{
				$tokens[] = $event + ['event' => $index];
			}
		}
		
		$calls = $this->getCalls(self::joinStrings($tokens), count($events));
		
		// a message taken ends the comment it was given
		$taken = [];
		foreach($calls as $call)
		{
			$taken[$call['close']] = true;
		}
		
		$comments = self::getComments($events, $taken);
		
		foreach($calls as $call)
		{
			// an empty msgid is the header's: xgettext skips it, yet it took the comment
			if($call['msgid'] === '')
			{
				continue;
			}
			
			$entry = new Entry($call['msgid'], $call['plural'], $call['context']);
			$key = $entry->getKey();
			$entries[$key] ??= $entry;
			$entries[$key]->plural ??= $call['plural'];
			
			$reference = $path . ':' . $call['line'];
			if(in_array($reference, $entries[$key]->references, true) === false)
			{
				$entries[$key]->references[] = $reference;
			}
			
			foreach(self::getTaggedLines($comments[$call['event']] ?? []) as $line)
			{
				if(in_array($line, $entries[$key]->extractedComments, true) === false)
				{
					$entries[$key]->extractedComments[] = $line;
				}
			}
		}
	}
	
	/**
	 * Sets the php-format flag of each entry
	 *
	 * @param array<string, Entry> $entries
	 * @return array<string, Entry>
	 */
	protected function finish(
		array $entries,
	): array
	{
		foreach($entries as $entry)
		{
			$entry->flags = self::isPhpFormat($entry->msgid, $entry->plural)
				? [self::FLAG_PHP_FORMAT]
				: [];
		}
		
		return $entries;
	}
	
	/**
	 * A bare "<?" opens PHP for xgettext whatever short_open_tag says, and so
	 * does <script language="php"> up to its </script>; both are rewritten to
	 * the long tags, on the same lines
	 */
	protected static function openShortTags(
		string $code,
	): string
	{
		$offset = 0;
		while(true)
		{
			$found = null;
			$position = 0;
			foreach(token_get_all($code) as $token)
			{
				$text = is_array($token)
					? $token[1]
					: $token;
					
				if($position >= $offset
					&& is_array($token)
					&& $token[0] === T_INLINE_HTML
					&& preg_match('/<\?(?!=|php(?:\s|$)|xml)|<script\s+language\s*=\s*(["\']?)php\1\s*>/i', $text, $match, PREG_OFFSET_CAPTURE))
				{
					$found = [$position + $match[0][1], $match[0][0]];
					
					break;
				}
				
				$position += strlen($text);
			}
			
			if($found === null)
			{
				return $code;
			}
			
			[$at, $tag] = $found;
			$code = substr_replace($code, '<?php ', $at, strlen($tag));
			$offset = $at + 6;
			
			if($tag[1] !== '?'
				&& preg_match('~</script\s*>~i', $code, $match, PREG_OFFSET_CAPTURE, $offset))
			{
				$code = substr_replace($code, '?>', $match[0][1], strlen($match[0][0]));
			}
		}
	}
	
	/**
	 * The code as xgettext reads it, in order: tokens (strings, symbols,
	 * brackets, commas, dots, anything else), comments with their lines, and
	 * the line ends of the PHP code. A string with variables in it is one
	 * token, after the code of its {$...} parts; a "#[" attribute is a "#"
	 * comment to the end of its line.
	 *
	 * @return list<array<string, mixed>>
	 */
	protected static function getEvents(
		string $code,
	): array
	{
		$events = [];
		$all = token_get_all($code);
		$line = 1;
		self::addEvents($all, 0, count($all), $line, $events);
		
		return $events;
	}
	
	/**
	 * The events of the tokens from $from up to $to
	 */
	protected static function addEvents(
		array $all,
		int $from,
		int $to,
		int &$line,
		array &$events,
	): void
	{
		for($index = $from; $index < $to; $index++)
		{
			$token = $all[$index];
			if(is_array($token))
			{
				$line = $token[2];
			}
			
			$text = is_array($token)
				? $token[1]
				: $token;
			$id = is_array($token)
				? $token[0]
				: $token;
				
			if($id === '"' || $id === '`' || $id === T_START_HEREDOC)
			{
				$index = self::addQuoted($all, $index, $to, $line, $events);
				
				continue;
			}
			
			if($id === T_ATTRIBUTE)
			{
				[$index, $taken] = self::readAttribute($all, $index, $line, $events);
				$line += substr_count($taken, "\n");
				
				continue;
			}
			
			if($id === T_CLOSE_TAG)
			{
				// the line break after a closing tag is the page's
				$events[] = self::token('other', '?>', $line);
			}
			else if($id === T_OPEN_TAG_WITH_ECHO)
			{
				$events[] = self::token('other', '=', $line);
			}
			else if($id === T_OPEN_TAG
				|| $id === T_WHITESPACE)
			{
				self::addLineEnds($events, $text, $line);
			}
			else if($id === T_COMMENT
				|| $id === T_DOC_COMMENT)
			{
				$events[] = [
					'kind' => 'comment',
					'lines' => self::readComment($text),
					'line' => $line + substr_count($text, "\n"),
				];
			}
			else if($id === T_CONSTANT_ENCAPSED_STRING)
			{
				$events[] = self::token('string', $text, $line, self::readString($text));
			}
			else if(in_array($id, ['(', ')', '[', ']', ',', '.'], true))
			{
				$events[] = self::token($id, $text, $line);
			}
			else if(preg_match(self::NAME, $text, $match) === 1)
			{
				$events[] = self::token('symbol', $text, $line, $match[1]);
			}
			else if($id !== T_INLINE_HTML)
			{
				$events[] = self::token('other', $text, $line);
			}
			
			$line += substr_count($text, "\n");
		}
	}
	
	/**
	 * @return array<string, mixed>
	 */
	protected static function token(
		string $type,
		string $text,
		int $line,
		?string $value = null,
	): array
	{
		return [
			'kind' => 'token',
			'type' => $type,
			'text' => $text,
			'line' => $line,
			'value' => $value,
		];
	}
	
	protected static function addLineEnds(
		array &$events,
		string $text,
		int $line,
	): void
	{
		$count = substr_count($text, "\n");
		for($number = 0; $number < $count; $number++)
		{
			$events[] = [
				'kind' => 'newline',
				'line' => $line + $number,
			];
		}
	}
	
	/**
	 * A double-quoted string, a backtick string or a heredoc from its first
	 * token to its last: the code of each {$...} part between "{$" and "}"
	 * marks, then a string token when nothing is interpolated, else one
	 * other token
	 *
	 * @return int the index of the last token
	 */
	protected static function addQuoted(
		array $all,
		int $index,
		int $to,
		int &$line,
		array &$events,
	): int
	{
		$first = $all[$index];
		$start = $line;
		$closing = is_array($first)
			? T_END_HEREDOC
			: $first;
		$text = is_array($first)
			? $first[1]
			: $first;
		$parts = [];
		$code = [];
		$depth = 0;
		
		for($last = $index + 1; $last < $to; $last++)
		{
			$token = $all[$last];
			$id = is_array($token)
				? $token[0]
				: $token;
			$text .= is_array($token)
				? $token[1]
				: $token;
				
			if($depth === 0
				&& $id === $closing)
			{
				break;
			}
			
			if($id === T_CURLY_OPEN
				|| $id === T_DOLLAR_OPEN_CURLY_BRACES
				|| ($depth > 0 && $id === '{'))
			{
				if($depth === 0 && $id === T_CURLY_OPEN)
				{
					$code[] = [$last + 1, null];
				}
				
				$depth++;
			}
			else if($depth > 0 && $id === '}')
			{
				$depth--;
				if($depth === 0 && $code !== [] && $code[array_key_last($code)][1] === null)
				{
					$code[array_key_last($code)][1] = $last;
				}
			}
			
			if($depth === 0)
			{
				$parts[] = $token;
			}
		}
		
		// xgettext reads the code in {$...} as code of its own
		foreach($code as [$from, $until])
		{
			$events[] = self::token('{$', '{$', $line);
			self::addEvents($all, $from, $until ?? $last, $line, $events);
			$events[] = self::token('}', '}', $line);
		}
		
		$literal = is_array($first)
			&& count($parts) <= 1
			&& (($parts[0][0] ?? T_ENCAPSED_AND_WHITESPACE) === T_ENCAPSED_AND_WHITESPACE)
			&& is_array($all[$last] ?? null);
			
		$events[] = $literal
			? self::token('string', $text, $start, self::readHeredoc($first[1], $parts[0][1] ?? '', $all[$last][1]))
			: self::token('other', $text, $start);
		$line = $start + substr_count($text, "\n");
		
		return $last;
	}
	
	/**
	 * xgettext takes "#" for a comment to the end of the line, so an
	 * attribute's line is a comment and its code is never read. The token
	 * holding the line break is taken whole: white space as line ends, a
	 * string or a comment running on as nothing
	 *
	 * @return array{0: int, 1: string} the index of the last token taken and
	 *     the text taken
	 */
	protected static function readAttribute(
		array $all,
		int $index,
		int $line,
		array &$events,
	): array
	{
		$comment = '#[';
		$taken = '#[';
		$count = count($all);
		
		for($next = $index + 1; $next < $count; $next++)
		{
			$part = is_array($all[$next])
				? $all[$next][1]
				: $all[$next];
			$taken .= $part;
			$break = strpos($part, "\n");
			if($break === false)
			{
				$comment .= $part;
				
				continue;
			}
			
			$comment .= substr($part, 0, $break);
			
			break;
		}
		
		$events[] = [
			'kind' => 'comment',
			'lines' => self::readComment($comment),
			'line' => $line,
		];
		
		if(is_array($all[$next] ?? null)
			&& $all[$next][0] === T_WHITESPACE)
		{
			self::addLineEnds($events, $all[$next][1], $line);
		}
		
		return [$next, $taken];
	}
	
	/**
	 * A comment's lines as xgettext keeps them: without the comment marks,
	 * the white space at their start and their end
	 *
	 * @return string[]
	 */
	protected static function readComment(
		string $text,
	): array
	{
		if(str_starts_with($text, '/*'))
		{
			$body = substr($text, 2);
			$lines = explode("\n", str_ends_with($body, '*/') ? substr($body, 0, -2) : $body);
		}
		else
		{
			$lines = [substr($text, str_starts_with($text, '#') ? 1 : 2)];
		}
		
		foreach($lines as $number => $line)
		{
			$lines[$number] = rtrim(ltrim($line, " \t"), " \t\r");
		}
		
		return $lines;
	}
	
	/**
	 * Joins string literals with "." as xgettext does: from a string that
	 * does not follow a dot, an operator or a ")", as long as the string
	 * added is not followed by "*" or "/"
	 *
	 * @param list<array<string, mixed>> $tokens
	 * @return list<array<string, mixed>>
	 */
	protected static function joinStrings(
		array $tokens,
	): array
	{
		$joined = [];
		$count = count($tokens);
		
		for($index = 0; $index < $count; $index++)
		{
			$token = $tokens[$index];
			$previous = $joined === []
				? ''
				: $joined[array_key_last($joined)]['text'];
				
			if($token['type'] === 'string'
				&& in_array(substr($previous, -1), self::UNCHAINED_AFTER, true) === false)
			{
				while(($tokens[$index + 1]['type'] ?? null) === '.'
					&& ($tokens[$index + 2]['type'] ?? null) === 'string'
					&& in_array(($tokens[$index + 3]['text'] ?? ' ')[0], ['*', '/'], true) === false)
				{
					$token['value'] .= $tokens[$index + 2]['value'];
					$index += 2;
				}
			}
			
			$joined[] = $token;
		}
		
		return $joined;
	}
	
	/**
	 * The keyword calls whose strings a shape finds, in the order their
	 * closing ")" comes, as xgettext takes them
	 *
	 * @param list<array<string, mixed>> $tokens
	 * @return list<array{msgid: string, plural: ?string, context: ?string, line: int, event: int, close: int}>
	 */
	protected function getCalls(
		array $tokens,
		int $end,
	): array
	{
		$calls = [];
		$count = count($tokens);
		
		for($index = 0; $index < $count; $index++)
		{
			$token = $tokens[$index];
			if($token['type'] !== 'symbol'
				|| isset($this->keywords[$token['value']]) === false
				|| ($tokens[$index + 1]['type'] ?? null) !== '(')
			{
				continue;
			}
			
			// the first string of each argument outside inner brackets
			$strings = [];
			$argument = 1;
			$open = [];
			$close = $end;
			for($next = $index + 2; $next < $count; $next++)
			{
				$type = $tokens[$next]['type'];
				if($type === '(' || $type === '[' || $type === '{$')
				{
					$open[] = match($type)
					{
						'(' => ')',
						'[' => ']',
						default => '}',
					};
				}
				else if($type === ')' || $type === ']' || $type === '}')
				{
					if($open === [])
					{
						// the end of a {$...} part ends a call left open in it
						if($type !== ']')
						{
							$close = $tokens[$next]['event'];
							
							break;
						}
					}
					// a closing bracket that does not match is skipped, as in xgettext
					else if($open[array_key_last($open)] === $type)
					{
						array_pop($open);
					}
				}
				else if($open === [])
				{
					if($type === ',')
					{
						$argument++;
					}
					else if($type === 'string')
					{
						$strings[$argument] ??= $tokens[$next];
					}
				}
			}
			
			$call = $this->getCall($this->keywords[$token['value']], $strings, $argument);
			if($call !== null)
			{
				$calls[] = $call + ['close' => $close];
			}
		}
		
		usort($calls, static fn(array $a, array $b): int => $a['close'] <=> $b['close']);
		
		return $calls;
	}
	
	/**
	 * The message of the shape that reads the most of a call, null when no
	 * shape finds its strings; an empty msgid is given too
	 *
	 * @param list<array{msgid: int, plural: ?int, context: ?int, total: ?int}> $shapes
	 * @param array<int, array<string, mixed>> $strings
	 * @return ?array{msgid: string, plural: ?string, context: ?string, line: int, event: int}
	 */
	protected function getCall(
		array $shapes,
		array $strings,
		int $arguments,
	): ?array
	{
		$best = null;
		$bestRank = -1;
		foreach($shapes as $shape)
		{
			if(isset($strings[$shape['msgid']]) === false
				|| ($shape['plural'] !== null && isset($strings[$shape['plural']]) === false)
				|| ($shape['context'] !== null && isset($strings[$shape['context']]) === false)
				|| ($shape['total'] !== null && $shape['total'] !== $arguments))
			{
				continue;
			}
			
			$rank = ($shape['context'] === null ? 0 : 2) + ($shape['plural'] === null ? 0 : 1);
			if($rank > $bestRank)
			{
				$best = $shape;
				$bestRank = $rank;
			}
		}
		
		if($best === null)
		{
			return null;
		}
		
		$msgid = $strings[$best['msgid']];
		$text = self::cutAtNul($msgid['value']);
		
		return [
			'msgid' => $text,
			'plural' => $best['plural'] === null
				? null
				: self::cutAtNul($strings[$best['plural']]['value']),
			'context' => $best['context'] === null
				? null
				: self::cutAtNul($strings[$best['context']]['value']),
			'line' => $msgid['line'],
			'event' => $msgid['event'],
		];
	}
	
	/**
	 * xgettext keeps C strings: a NUL ends the text
	 */
	protected static function cutAtNul(
		string $text,
	): string
	{
		$nul = strpos($text, chr(0));
		
		return $nul === false
			? $text
			: substr($text, 0, $nul);
	}
	
	/**
	 * The comment lines each string token was given: the comments since the
	 * last line of code, kept over empty lines and lines of comments, dropped
	 * at the end of a line holding code after them, and once a message took them
	 *
	 * @param list<array<string, mixed>> $events
	 * @param array<int, true> $taken
	 * @return array<int, string[]> event index => lines
	 */
	protected static function getComments(
		array $events,
		array $taken,
	): array
	{
		$comment = [];
		$commentLine = 0;
		$codeLine = 0;
		$given = [];
		
		foreach($events as $index => $event)
		{
			if($event['kind'] === 'comment')
			{
				$comment = [...$comment, ...$event['lines']];
				$commentLine = $event['line'];
			}
			else if($event['kind'] === 'newline')
			{
				if($codeLine > $commentLine)
				{
					$comment = [];
				}
			}
			else if($event['kind'] === 'token')
			{
				$codeLine = $event['line'];
				if($event['type'] === 'string')
				{
					$given[$index] = $comment;
				}
				
				if(isset($taken[$index]))
				{
					$comment = [];
				}
			}
		}
		
		return $given;
	}
	
	/**
	 * The lines from the one holding COMMENT_TAG on, the tag's line from the
	 * tag; a later line starting with what stood before the tag loses that
	 *
	 * @param string[] $lines
	 * @return string[]
	 */
	protected static function getTaggedLines(
		array $lines,
	): array
	{
		$tagged = [];
		$prefix = null;
		foreach($lines as $line)
		{
			if($prefix === null)
			{
				$position = strpos($line, self::COMMENT_TAG);
				if($position === false)
				{
					continue;
				}
				
				$prefix = substr($line, 0, $position);
				$tagged[] = substr($line, $position);
				
				continue;
			}
			
			$tagged[] = $prefix !== '' && str_starts_with($line, $prefix)
				? substr($line, strlen($prefix))
				: $line;
		}
		
		return $tagged;
	}
	
	/**
	 * xgettext's guess: a msgid is a PHP format string when it holds a
	 * directive and nothing that would break one. The plural decides when it
	 * holds a directive or a broken one, else the msgid does.
	 */
	public static function isPhpFormat(
		string $msgid,
		?string $plural = null,
	): bool
	{
		$verdict = $plural === null
			? null
			: self::getFormatVerdict($plural);
			
		return $verdict ?? self::getFormatVerdict($msgid) ?? false;
	}
	
	/**
	 * true with directives only, false with a broken one, null with none
	 */
	protected static function getFormatVerdict(
		string $text,
	): ?bool
	{
		$rest = preg_replace(self::DIRECTIVE, '', $text, -1, $count);
		if(str_contains((string)$rest, '%'))
		{
			return false;
		}
		
		return $count > 0
			? true
			: null;
	}
	
	/**
	 * A heredoc's text: the closing marker's indentation taken off every line
	 * and the last line break dropped, escapes read as in double quotes but
	 * for a quote, a nowdoc's text as written
	 */
	protected static function readHeredoc(
		string $start,
		string $body,
		string $end,
	): string
	{
		$indent = substr($end, 0, strspn($end, " \t"));
		$body = preg_replace('/\r?\n$/', '', $body);
		if($indent !== '')
		{
			$lines = explode("\n", $body);
			foreach($lines as $number => $text)
			{
				if(str_starts_with($text, $indent))
				{
					$lines[$number] = substr($text, strlen($indent));
				}
			}
			
			$body = implode("\n", $lines);
		}
		
		return str_contains($start, "'")
			? $body
			: self::unescape($body, false);
	}
	
	/**
	 * A quoted literal's text, read as xgettext reads it
	 */
	protected static function readString(
		string $literal,
	): string
	{
		$quote = $literal[0];
		if($quote === 'b' || $quote === 'B')
		{
			$literal = substr($literal, 1);
			$quote = $literal[0];
		}
		
		$text = substr($literal, 1, -1);
		if($quote === "'")
		{
			return preg_replace_callback(
				"/\\\\([\\\\'])/",
				static fn(array $match): string => $match[1],
				$text,
			);
		}
		
		return self::unescape($text, true);
	}
	
	/**
	 * Escapes of a double-quoted or heredoc text, those xgettext knows
	 */
	protected static function unescape(
		string $text,
		bool $quoted,
	): string
	{
		return preg_replace_callback(
			'/\\\\(?:([0-7]{1,3})|x([0-9A-Fa-f]{1,2})|(.))/s',
			static function(array $match) use ($quoted): string
			{
				if(($match[1] ?? '') !== '')
				{
					return chr(octdec($match[1]) & 0xFF);
				}
				
				if(($match[2] ?? '') !== '')
				{
					return chr(hexdec($match[2]));
				}
				
				return match($match[3])
				{
					'n' => "\n",
					't' => "\t",
					'r' => "\r",
					'\\' => '\\',
					'$' => '$',
					'"' => $quoted
						? '"'
						: '\\"',
					default => '\\' . $match[3],
				};
			},
			$text,
		);
	}
}
