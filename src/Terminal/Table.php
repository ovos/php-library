<?php
declare(strict_types=1);

namespace Ovos\Terminal;

use function array_fill;
use function array_map;
use function ceil;
use function count;
use function explode;
use function floor;
use function implode;
use function max;
use function mb_strwidth;
use function preg_replace;
use function rtrim;
use function str_repeat;
use function str_replace;

use const PHP_EOL;

/**
 * Table
 *
 * Small, dependency-free terminal table renderer (replaces the former
 * symfony/console wrapper). Markup-aware through Terminal\Formatter:
 * column widths are measured on the *visible* text, so colored cells and
 * wide (CJK) characters still line up.
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
	
	protected bool $markup = false;
	
	protected string $style = self::STYLE_UNICODE;
	
	/**
	 * Spaces of padding either side of a cell's content
	 */
	protected int $padding = 1;
	
	public function hasMarkup(
		bool $markup = true,
	): static
	{
		$this->markup = $markup;
		
		return $this;
	}
	
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
		$this->headers = array_map($this->stringify(...), $headers);
		
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
		$this->rows[] = array_map($this->stringify(...), $row);
		
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
	 * Resolves markup, then pads the visible content to $width per alignment.
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
	 * The string to print: convert <color> markup to ANSI when markup is on,
	 * otherwise strip both the markup tokens and any raw ANSI.
	 */
	protected function display(
		string $content,
	): string
	{
		if($this->markup)
		{
			return Formatter::handleMarkup($content);
		}
		
		return $this->stripAnsi(Formatter::stripMarkup($content));
	}
	
	/**
	 * Visible width: strip <color> tokens and raw ANSI, then measure.
	 */
	protected function width(
		string $content,
	): int
	{
		return mb_strwidth($this->stripAnsi(Formatter::stripMarkup($content)));
	}
	
	protected function stripAnsi(
		string $content,
	): string
	{
		return (string)preg_replace('/\e\[[0-9;]*m/', '', $content);
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
