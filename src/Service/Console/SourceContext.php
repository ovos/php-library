<?php
declare(strict_types=1);

namespace Ovos\Service\Console;

use function file;
use function filesize;
use function is_file;
use function is_readable;
use function count;
use function max;
use function min;
use function mb_strlen;
use function mb_substr;
use function preg_match;
use function rtrim;

use const FILE_IGNORE_NEW_LINES;

/**
 * Reads a window of source lines around an error location for the
 * console detail panel (and the AI explain prompt).
 *
 * Best-effort by the sender contract: any unreadable, generated, or
 * oversized file yields null rather than throwing. Line strings are
 * right-trimmed and length-capped so a minified or pathological file
 * cannot inflate the payload.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
final class SourceContext
{
	/**
	 * Lines of context on each side of the error line
	 */
	public const int RADIUS = 5;
	
	/**
	 * Per-line character cap (minified bundles have thousand-char lines)
	 */
	public const int MAX_LINE = 240;
	
	/**
	 * Skip files larger than this — a huge or generated file is never
	 * useful context and reading it wastes memory
	 */
	public const int MAX_FILE_BYTES = 2097152; // 2 MiB
	
	/**
	 * A window of source around $line, or null when the file cannot be
	 * read as real source.
	 *
	 * @return array{start: int, line: int, lines: string[]}|null
	 */
	public static function read(
		string $file,
		int $line,
		int $radius = self::RADIUS,
	): ?array
	{
		if($line < 1 || self::isReadableSource($file) === false)
		{
			return null;
		}
		
		$all = @file($file, FILE_IGNORE_NEW_LINES);
		if($all === false || $all === [])
		{
			return null;
		}
		
		$total = count($all);
		if($line > $total)
		{
			return null;
		}
		
		// 1-based line numbers -> 0-based array indices
		$start = max(1, $line - $radius);
		$end = min($total, $line + $radius);
		
		$lines = [];
		for($n = $start; $n <= $end; $n++)
		{
			$lines[] = self::cap(rtrim($all[$n - 1]));
		}
		
		return [
			'start' => $start,
			'line' => $line,
			'lines' => $lines,
		];
	}
	
	/**
	 * A real, readable, reasonably-sized file on disk — not an eval()'d
	 * frame, the CLI's "Command line code", a stream wrapper, or a giant
	 */
	protected static function isReadableSource(
		string $file,
	): bool
	{
		if($file === ''
			|| preg_match('~^(php|data|phar)://|\beval\(\)|Command line code~i', $file) === 1)
		{
			return false;
		}
		
		return is_file($file)
			&& is_readable($file)
			&& (int)@filesize($file) <= self::MAX_FILE_BYTES;
	}
	
	protected static function cap(
		string $value,
	): string
	{
		return mb_strlen($value) > self::MAX_LINE
			? mb_substr($value, 0, self::MAX_LINE) . '…'
			: $value;
	}
}
