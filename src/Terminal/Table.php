<?php
declare(strict_types=1);

namespace Ovos\Terminal;

use function array_fill;
use function array_map;
use function array_values;
use function ceil;
use function count;
use function explode;
use function floor;
use function implode;
use function max;
use function mb_strwidth;
use function rtrim;
use function str_repeat;
use function str_replace;

use const PHP_EOL;

/**
 * Table
 *
 * Small, dependency-free terminal table renderer (replaces the former
 * symfony/console wrapper). Markup-aware through Terminal\Formatter: column
 * widths are measured on the *visible* text, so colored cells and wide (CJK)
 * characters still line up.
 *
 * It never resolves that markup, though — the rendered table carries the
 * <color> tokens it was given, and whoever prints it decides. Hand it to
 * Controller\Cli::log() or Terminal::output() and the environment decides for
 * you; append it to a Response body and you must resolve it yourself, with
 * Terminal::getMessage($table->getTable(), $response->getColoredOutput()).
 *
 * That split is deliberate: a table that resolved its own colour decided for
 * every consumer of the string at once, and they disagree — a cron log has to
 * stay plain while the profiler's browser pane, which reads the same message
 * before Terminal::output() formats it, should still show colour.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Table
{
	// border styles
	public const string STYLE_UNICODE = 'unicode';
	public const string STYLE_ASCII = 'ascii';
	public const string STYLE_NONE = 'none';
	
	// column alignment
	public const string ALIGN_LEFT = 'left';
	public const string ALIGN_RIGHT = 'right';
	public const string ALIGN_CENTER = 'center';
	
	// sentinel row that renders as a horizontal rule
	protected const string SEPARATOR = "\0separator\0";
	
	/**
	 * Box-drawing characters per style. Keys: t/m/b = top/middle/bottom rule,
	 * l/m/r = left/junction/right, h = horizontal, v = vertical.
	 *
	 * @var array<string, array<string, string>>
	 */
	protected static array $charsets =
	[
		self::STYLE_UNICODE =>
		[
			'tl' => '┌', 'tm' => '┬', 'tr' => '┐',
			'ml' => '├', 'mm' => '┼', 'mr' => '┤',
			'bl' => '└', 'bm' => '┴', 'br' => '┘',
			'h' => '─', 'v' => '│',
		],
		self::STYLE_ASCII =>
		[
			'tl' => '+', 'tm' => '+', 'tr' => '+',
			'ml' => '+', 'mm' => '+', 'mr' => '+',
			'bl' => '+', 'bm' => '+', 'br' => '+',
			'h' => '-', 'v' => '|',
		],
		self::STYLE_NONE =>
		[
			'tl' => '', 'tm' => '', 'tr' => '',
			'ml' => '', 'mm' => '', 'mr' => '',
			'bl' => '', 'bm' => '', 'br' => '',
			'h' => '', 'v' => '',
		],
	];
	
	protected array $headers = [];
	
	protected array $rows = [];
	
	/**
	 * Per-column alignment: column index => self::ALIGN_*
	 *
	 * @var array<int, string>
	 */
	protected array $alignments = [];
	
	protected string $style = self::STYLE_UNICODE;
	
	/**
	 * Spaces of padding either side of a cell's content
	 */
	protected int $padding = 1;
	
	public function setStyle(
		string $style,
	): static
	{
		$this->style = $style;
		
		return $this;
	}
	
	public function setPadding(
		int $padding,
	): static
	{
		$this->padding = max(0, $padding);
		
		return $this;
	}
	
	public function setHeaders(
		array $headers,
	): static
	{
		// array_values: columns are positional, so drop any keys the caller
		// passed (associative/sparse rows would otherwise misalign)
		$this->headers = array_values(array_map($this->stringify(...), $headers));
		
		return $this;
	}
	
	public function setAlignment(
		int $column,
		string $alignment,
	): static
	{
		$this->alignments[$column] = $alignment;
		
		return $this;
	}
	
	/**
	 * @param array<int, string> $alignments
	 */
	public function setAlignments(
		array $alignments,
	): static
	{
		foreach($alignments as $column => $alignment)
		{
			$this->setAlignment((int)$column, $alignment);
		}
		
		return $this;
	}
	
	public function addRow(
		array $row,
	): static
	{
		$this->rows[] = array_values(array_map($this->stringify(...), $row));
		
		return $this;
	}
	
	/**
	 * @param array<int, array> $rows
	 */
	public function addRows(
		array $rows,
	): static
	{
		foreach($rows as $row)
		{
			$this->addRow($row);
		}
		
		return $this;
	}
	
	public function addSeparator(): static
	{
		$this->rows[] = self::SEPARATOR;
		
		return $this;
	}
	
	public function getTable(): string
	{
		$columns = $this->countColumns();
		if($columns === 0)
		{
			return '';
		}
		
		$widths = $this->columnWidths($columns);
		$chars = self::$charsets[$this->style] ?? self::$charsets[self::STYLE_UNICODE];
		
		$lines = [];
		
		if($top = $this->rule($widths, $chars, 'tl', 'tm', 'tr'))
		{
			$lines[] = $top;
		}
		
		if($this->headers !== [])
		{
			$lines[] = $this->renderRow($this->headers, $widths, $columns, $chars);
			
			if($rule = $this->rule($widths, $chars, 'ml', 'mm', 'mr'))
			{
				$lines[] = $rule;
			}
		}
		
		foreach($this->rows as $row)
		{
			if($row === self::SEPARATOR)
			{
				if($rule = $this->rule($widths, $chars, 'ml', 'mm', 'mr'))
				{
					$lines[] = $rule;
				}
				
				continue;
			}
			
			$lines[] = $this->renderRow($row, $widths, $columns, $chars);
		}
		
		if($bottom = $this->rule($widths, $chars, 'bl', 'bm', 'br'))
		{
			$lines[] = $bottom;
		}
		
		return implode(PHP_EOL, $lines) . PHP_EOL;
	}
	
	public function __toString(): string
	{
		return $this->getTable();
	}
	
	protected function stringify(
		mixed $value,
	): string
	{
		return (string)$value;
	}
	
	protected function countColumns(): int
	{
		$columns = count($this->headers);
		
		foreach($this->rows as $row)
		{
			if($row === self::SEPARATOR)
			{
				continue;
			}
			
			$columns = max($columns, count($row));
		}
		
		return $columns;
	}
	
	/**
	 * @return int[]
	 */
	protected function columnWidths(
		int $columns,
	): array
	{
		$widths = array_fill(0, $columns, 0);
		
		$rows = $this->rows;
		if($this->headers !== [])
		{
			$rows[] = $this->headers;
		}
		
		foreach($rows as $row)
		{
			if($row === self::SEPARATOR)
			{
				continue;
			}
			
			for($column = 0; $column < $columns; $column++)
			{
				foreach($this->cellLines($row[$column] ?? '') as $line)
				{
					$widths[$column] = max($widths[$column], $this->width($line));
				}
			}
		}
		
		return $widths;
	}
	
	/**
	 * Splits a cell into display lines, dropping a single trailing newline so a
	 * spacer row such as [PHP_EOL] renders as one blank line, not two.
	 *
	 * @return string[]
	 */
	protected function cellLines(
		string $cell,
	): array
	{
		$cell = rtrim($cell, "\r\n");
		
		return explode("\n", str_replace("\r\n", "\n", $cell));
	}
	
	protected function renderRow(
		array $row,
		array $widths,
		int $columns,
		array $chars,
	): string
	{
		// split every cell into lines and find the tallest
		$cells = [];
		$height = 1;
		for($column = 0; $column < $columns; $column++)
		{
			$cells[$column] = $this->cellLines($row[$column] ?? '');
			$height = max($height, count($cells[$column]));
		}
		
		$pad = str_repeat(' ', $this->padding);
		$out = [];
		
		for($line = 0; $line < $height; $line++)
		{
			$rendered = [];
			for($column = 0; $column < $columns; $column++)
			{
				$content = $cells[$column][$line] ?? '';
				$rendered[] = $pad . $this->formatCell($content, $widths[$column], $column) . $pad;
			}
			
			$out[] = $chars['v'] . implode($chars['v'], $rendered) . $chars['v'];
		}
		
		return implode(PHP_EOL, $out);
	}
	
	/**
	 * Pads the visible content to $width per the column alignment.
	 */
	protected function formatCell(
		string $content,
		int $width,
		int $column,
	): string
	{
		$space = max(0, $width - $this->width($content));
		$display = $this->display($content);
		
		return match($this->alignments[$column] ?? self::ALIGN_LEFT)
		{
			self::ALIGN_RIGHT => str_repeat(' ', $space) . $display,
			self::ALIGN_CENTER => str_repeat(' ', (int)floor($space / 2))
				. $display
				. str_repeat(' ', (int)ceil($space / 2)),
			default => $display . str_repeat(' ', $space),
		};
	}
	
	/**
	 * The string to print. Control bytes go — a cell's own colour arrives as
	 * markup, so a raw escape in one came from data, and data has no business
	 * acting on a terminal.
	 *
	 * The <color> markup itself is handed on untouched: resolving it here would
	 * decide for every consumer of the string at once, and they disagree — a cron
	 * log must stay plain while the profiler pane, which sees the same message
	 * before Terminal::output() formats it, should show colour. Whoever prints the
	 * table resolves it: log() and output() already do, per environment.
	 */
	protected function display(
		string $content,
	): string
	{
		return Formatter::stripControls($content);
	}
	
	/**
	 * Visible width: strip <color> tokens and every escape, then measure. The
	 * two must agree with display() or the box stops lining up.
	 */
	protected function width(
		string $content,
	): int
	{
		return mb_strwidth(
			Formatter::stripControls(Formatter::stripMarkup($content)),
		);
	}
	
	/**
	 * Builds a horizontal rule, or '' for the borderless style.
	 */
	protected function rule(
		array $widths,
		array $chars,
		string $left,
		string $junction,
		string $right,
	): string
	{
		if($chars['h'] === '')
		{
			return '';
		}
		
		$segments = [];
		foreach($widths as $width)
		{
			$segments[] = str_repeat($chars['h'], $width + $this->padding * 2);
		}
		
		return $chars[$left] . implode($chars[$junction], $segments) . $chars[$right];
	}
}
