<?php
declare(strict_types=1);

namespace Ovos\Terminal;

use function array_keys;
use function array_shift;
use function end;
use function explode;
use function implode;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function preg_replace_callback;
use function rtrim;
use function str_contains;
use function strrpos;
use function strtolower;
use function strtoupper;
use function substr;

/**
 * Highlighter
 *
 * Adds <color> markup to the values shown in CLI profiler tables. Emits markup
 * tokens rather than raw ANSI on purpose: Terminal\Table measures column widths
 * on the visible text and strips the tokens again when color is off, so the
 * same cell is correct either way.
 *
 * Every entry point sanitizes its input first — a bound query value or a redis
 * argument is user data, and must not be able to smuggle markup or an escape
 * sequence into the terminal.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Highlighter
{
	// slow-query thresholds in seconds, against the value Measurements
	// formats into the profiler report
	public const float TIME_WARN = 0.01;
	public const float TIME_ALERT = 0.1;
	
	// allocation thresholds in bytes
	public const int MEMORY_WARN = 102400; // 100 KB
	public const int MEMORY_ALERT = 1048576; // 1 MB
	
	/**
	 * SQL keywords worth picking out: statement verbs, clauses, and the
	 * operators that carry the structure of a query. Multi-word entries must
	 * precede their shorter prefixes — alternation is first-match-wins.
	 */
	protected const array SQL_KEYWORDS =
	[
		'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE',
		'CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME',
		'INTO', 'VALUES', 'SET', 'FROM', 'WHERE', 'HAVING',
		'GROUP BY', 'ORDER BY', 'PARTITION BY', 'LIMIT', 'OFFSET',
		'INNER JOIN', 'LEFT JOIN', 'RIGHT JOIN', 'CROSS JOIN', 'STRAIGHT_JOIN',
		'JOIN', 'ON', 'USING', 'UNION ALL', 'UNION',
		'AND', 'OR', 'NOT', 'IN', 'EXISTS', 'BETWEEN', 'LIKE', 'IS',
		'AS', 'DISTINCT', 'CASE', 'WHEN', 'THEN', 'ELSE', 'END',
		'ASC', 'DESC', 'ON DUPLICATE KEY', 'IGNORE',
		'START TRANSACTION', 'BEGIN', 'COMMIT', 'ROLLBACK',
		'SHOW', 'DESCRIBE', 'EXPLAIN', 'ANALYZE', 'OPTIMIZE',
		'TABLE', 'INDEX', 'DATABASE', 'PRIMARY KEY', 'FOREIGN KEY',
		'FOR UPDATE', 'LOCK IN SHARE MODE',
	];
	
	/**
	 * Colorizes a query: keywords, NULL, then the quoted literals holding the
	 * bound values Pdo\Profiler\Reporter substituted in.
	 *
	 * Wrap before calling, not after — the markup tokens are invisible in the
	 * terminal but would count toward wordwrap()'s column budget.
	 */
	public static function sql(
		string $sql,
	): string
	{
		$sql = self::sanitize($sql);
		
		// the match is kept verbatim: a profiler shows the query as it was
		// sent, so no case normalisation (which would also rewrite a value)
		$sql = (string)preg_replace_callback(
			'/\b(?:' . implode('|', self::keywordPatterns()) . ')\b/i',
			static fn(array $match): string => '<cyan>' . $match[0] . '<reset>',
			$sql,
		);
		
		$sql = (string)preg_replace_callback('/\bNULL\b/i',
			static fn(array $match): string => '<darkpurple>' . $match[0] . '<reset>',
			$sql,
		);
		
		// literals last, backslash escapes honoured: the passes above may have
		// colored a keyword sitting inside a bound value, so the whole quoted
		// run is stripped and re-colored as a single literal
		$sql = (string)preg_replace_callback(
			"/'(?:[^'\\\\]|\\\\.)*'/s",
			static fn(array $match): string => '<brown>' . Formatter::stripMarkup($match[0]) . '<reset>',
			$sql,
		);
		
		return self::balanceLines($sql);
	}
	
	/**
	 * Colorizes a redis call as rendered by Redis\Profiler\Reporter: the
	 * command verb, then the keys, then the arguments — dimmed, so the command
	 * and the keys stay scannable in a wall of values.
	 */
	public static function redis(
		string $call,
	): string
	{
		$call = self::sanitize($call);
		
		$parts = explode(' ', $call);
		$command = array_shift($parts);
		if($command === null || $command === '')
		{
			return $call;
		}
		
		$rendered = ['<cyan>' . strtoupper($command) . '<reset>'];
		
		foreach($parts as $part)
		{
			// by position the keys lead the arguments, but not every command
			// reports them that way — FCALL puts the function name first — so
			// each token is judged on its own shape instead
			$rendered[] = self::isRedisKey($part)
				? '<green>' . $part . '<reset>'
				: '<gray>' . $part . '<reset>';
		}
		
		return self::balanceLines(implode(' ', $rendered));
	}
	
	/**
	 * Colorizes a formatted duration in seconds (Measurements::formatTime).
	 *
	 * The thresholds are per-caller: "slow" for a single query is nothing like
	 * "slow" for a whole request, and a scale borrowed from the wrong table
	 * paints every row red.
	 */
	public static function time(
		?string $time,
		float $warn = self::TIME_WARN,
		float $alert = self::TIME_ALERT,
	): string
	{
		if($time === null || $time === '')
		{
			return '';
		}
		
		$time = self::sanitize($time);
		$seconds = (float)$time;
		
		$color = match(true)
		{
			$seconds >= $alert => 'red',
			$seconds >= $warn => 'yellow',
			default => 'gray',
		};
		
		return '<' . $color . '>' . $time . '<reset>';
	}
	
	/**
	 * Colorizes a formatted size (Size::format, e.g. "1.5 MB")
	 */
	public static function memory(
		?string $memory,
		int $warn = self::MEMORY_WARN,
		int $alert = self::MEMORY_ALERT,
	): string
	{
		if($memory === null || $memory === '')
		{
			return '';
		}
		
		$memory = self::sanitize($memory);
		$bytes = self::parseSize($memory);
		
		$color = match(true)
		{
			$bytes >= $alert => 'red',
			$bytes >= $warn => 'yellow',
			default => 'gray',
		};
		
		return '<' . $color . '>' . $memory . '<reset>';
	}
	
	/**
	 * Colorizes a message type label (View\Helper\Messages\Message::TYPE_*)
	 */
	public static function messageType(
		string $type,
	): string
	{
		$color = match(strtolower(self::sanitize($type)))
		{
			'error' => 'red',
			'warning' => 'yellow',
			'success' => 'green',
			'info' => 'darkcyan',
			default => 'gray',
		};
		
		return '<' . $color . '>' . self::sanitize($type) . '<reset>';
	}
	
	/**
	 * Colorizes a class name, optionally with a ::member: the namespace is
	 * dimmed and the class itself kept bright, so a column of
	 * Migrations\Projects\* rows is scannable by what differs between them
	 */
	public static function className(
		string $class,
	): string
	{
		$class = self::sanitize($class);
		$member = '';
		
		if(str_contains($class, '::'))
		{
			[$class, $method] = explode('::', $class, 2);
			$member = '<gray>::<reset><white>' . $method . '<reset>';
		}
		
		$position = strrpos($class, '\\');
		if($position === false)
		{
			return '<cyan>' . $class . '<reset>' . $member;
		}
		
		return '<gray>' . substr($class, 0, $position + 1) . '<reset>'
			. '<cyan>' . substr($class, $position + 1) . '<reset>'
			. $member;
	}
	
	/**
	 * Emphasizes a table header label
	 */
	public static function header(
		string $header,
	): string
	{
		return '<white>' . self::sanitize($header) . '<reset>';
	}
	
	/**
	 * A profiler table's header: the count, and what the count leaves out.
	 *
	 * "Queries (12)" when the display limit never bit — mentioning a cap that
	 * dropped nothing is noise. "Queries (last 20 of 137)" when it did, which is
	 * the case worth spelling out: the table is a tail, and the reader would
	 * otherwise take 20 for the whole request.
	 */
	public static function heading(
		string $label,
		int $shown,
		int $total,
	): string
	{
		return self::header($total > $shown
			? $label . ' (last ' . $shown . ' of ' . $total . ')'
			: $label . ' (' . $shown . ')',
		);
	}
	
	/**
	 * Colorizes a count, but only once it counts: a colored "0" in a column of
	 * errors or failures reads as a signal where there is none
	 */
	public static function tally(
		?string $count,
		string $color = 'yellow',
	): string
	{
		$count = self::sanitize((string)$count);
		
		if($count === '')
		{
			return '';
		}
		
		return (float)$count === 0.0
			? '<gray>' . $count . '<reset>'
			: '<' . $color . '>' . $count . '<reset>';
	}
	
	/**
	 * Wraps a plain cell value in a color, for callers building their own
	 * tables. Empty stays empty — an unset column should not carry markup —
	 * and a multi-line value (an exception message) is balanced per line.
	 */
	public static function color(
		?string $value,
		string $color,
	): string
	{
		if($value === null || $value === '')
		{
			return '';
		}
		
		return self::balanceLines(
			'<' . $color . '>' . self::sanitize($value) . '<reset>',
		);
	}
	
	/**
	 * Strips anything that would colorize by itself: raw ANSI sequences, and
	 * markup tokens already present in the content
	 */
	public static function sanitize(
		string $content,
	): string
	{
		return Formatter::stripMarkup(
			(string)preg_replace('/\e\[[0-9;]*[a-zA-Z]/', '', $content),
		);
	}
	
	/**
	 * Closes every open color at a line break and re-opens it after: a wrapped
	 * cell is rendered line by line by Terminal\Table, so a color spanning the
	 * break would tint the padding and the border, and the continuation would
	 * lose the color entirely.
	 */
	protected static function balanceLines(
		string $content,
	): string
	{
		if(str_contains($content, "\n") === false)
		{
			return $content;
		}
		
		$reset = '<' . Formatter::COLOR_RESET . '>';
		$pattern = '/<(?:' . implode('|', array_keys(Formatter::$colors)) . ')>/';
		
		$balanced = [];
		$open = '';
		
		foreach(explode("\n", $content) as $line)
		{
			// normalise CRLF away — Terminal\Table does the same before it
			// splits a cell into display lines
			$line = rtrim($line, "\r");
			$carried = $open;
			
			if(preg_match_all($pattern, $line, $matches) > 0)
			{
				$last = (string)end($matches[0]);
				$open = $last === $reset
					? ''
					: $last;
			}
			
			$balanced[] = $carried . $line . ($open !== '' ? $reset : '');
		}
		
		return implode("\n", $balanced);
	}
	
	/**
	 * Keyword patterns with runs of whitespace made flexible, so a keyword
	 * split by a line wrap still matches
	 *
	 * @return string[]
	 */
	protected static function keywordPatterns(): array
	{
		$patterns = [];
		foreach(self::SQL_KEYWORDS as $keyword)
		{
			$patterns[] = (string)preg_replace('/\s+/', '\s+', $keyword);
		}
		
		return $patterns;
	}
	
	/**
	 * A redis key in these projects is namespaced with colons and carries no
	 * whitespace — see the console:* layout
	 */
	protected static function isRedisKey(
		string $part,
	): bool
	{
		return str_contains($part, ':')
			&& preg_match('/^[\w:.\-{}\[\]*@#]+$/', $part) === 1;
	}
	
	/**
	 * Turns a Size::format() string back into bytes, for thresholding
	 */
	protected static function parseSize(
		string $size,
	): float
	{
		if(preg_match('/^\s*(-?[\d.]+)\s*([KMGT]?B)?/i', $size, $match) !== 1)
		{
			return 0.0;
		}
		
		$multiplier = match(strtoupper($match[2] ?? 'B'))
		{
			'KB' => 1024,
			'MB' => 1024 ** 2,
			'GB' => 1024 ** 3,
			'TB' => 1024 ** 4,
			default => 1,
		};
		
		return (float)$match[1] * $multiplier;
	}
}
