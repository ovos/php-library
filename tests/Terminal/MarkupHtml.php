<?php
declare(strict_types=1);

namespace Tests\Terminal;

use Ovos\Terminal\Formatter as Subject;
use Ovos\Terminal\Highlighter;
use Ovos\Test;

use function str_contains;

/**
 * MarkupHtml - <color> markup resolved to spans for the profiler panel.
 *
 * The content between tags is user data (a bound query value lands here), so
 * escaping is the point of these tests, not the colours.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class MarkupHtml extends Test
{
	public function tagsBecomeTheClassesTheProfilerRenders(): bool
	{
		return Subject::handleMarkupHtml('<cyan>SELECT<reset> id')
			=== '<span class="term-cyan">SELECT</span> id'
			// a colour runs until the next tag, not just one word
			&& Subject::handleMarkupHtml('<gray>a b<reset>')
				=== '<span class="term-gray">a b</span>'
			// no markup at all still gets escaped
			&& Subject::handleMarkupHtml('plain & simple') === 'plain &amp; simple';
	}
	
	public function scriptInABoundValueStaysInert(): bool
	{
		$html = Subject::handleMarkupHtml(
			Highlighter::sql("SELECT id FROM t WHERE name = '<script>alert(1)</script>'"),
		);
		
		// the payload must survive only as text: no live tag, no raw quote
		return str_contains($html, '<script>') === false
			&& str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;')
			&& str_contains($html, '<span class="term-cyan">SELECT</span>');
	}
	
	public function markupInsideAColouredRunIsEscapedToo(): bool
	{
		// the escape has to happen per segment — a value inside a coloured run
		// must not slip through because the run itself is ours
		$html = Subject::handleMarkupHtml('<brown>\'<img src=x onerror=y>\'<reset>');
		
		return str_contains($html, '<img') === false
			&& str_contains($html, '&lt;img src=x onerror=y&gt;')
			&& str_contains($html, '<span class="term-brown">');
	}
	
	public function quotesAndAmpersandsBecomeEntities(): bool
	{
		$html = Subject::handleMarkupHtml('<white>a "b" & \'c\'<reset>');
		
		return str_contains($html, '&quot;b&quot;')
			&& str_contains($html, '&amp;')
			&& str_contains($html, '&#039;c&#039;');
	}
	
	public function anUnknownTagInContentIsNotATag(): bool
	{
		// only the 16 Formatter colours are markup; anything else is data
		$html = Subject::handleMarkupHtml('<cyan>SELECT<reset> <b>x</b> <turquoise>y');
		
		return $html === '<span class="term-cyan">SELECT</span>'
			. ' &lt;b&gt;x&lt;/b&gt; &lt;turquoise&gt;y';
	}
	
	public function resetClosesTheColour(): bool
	{
		$html = Subject::handleMarkupHtml('<red>hot<reset>cold');
		
		return $html === '<span class="term-red">hot</span>cold';
	}
}
