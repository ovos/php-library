<?php
declare(strict_types=1);

namespace Tests\Terminal;

use Ovos\Terminal\Formatter;
use Ovos\Terminal\Highlighter as Subject;
use Ovos\Terminal\Table;
use Ovos\Test;

use function array_unique;
use function count;
use function explode;
use function mb_strwidth;
use function preg_match;
use function rtrim;
use function str_contains;
use function str_repeat;
use function strlen;
use function substr_count;
use function wordwrap;

/**
 * Highlighter - the <color> markup added to the CLI profiler tables
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Highlighter extends Test
{
	public function sqlColorsKeywordsWithoutRewritingTheQuery(): bool
	{
		$highlighted = Subject::sql('SELECT id FROM users WHERE name = \'bob\'');
		
		// the visible text must survive untouched — a profiler shows the query
		// as it was sent
		return Formatter::stripMarkup($highlighted) === 'SELECT id FROM users WHERE name = \'bob\''
			&& str_contains($highlighted, '<cyan>SELECT<reset>')
			&& str_contains($highlighted, '<cyan>FROM<reset>')
			&& str_contains($highlighted, '<brown>\'bob\'<reset>');
	}
	
	public function sqlKeepsLowercaseKeywordsAsWritten(): bool
	{
		$highlighted = Subject::sql('select 1');
		
		return str_contains($highlighted, '<cyan>select<reset>')
			&& str_contains($highlighted, 'SELECT') === false;
	}
	
	public function sqlDoesNotColorKeywordsInsideABoundValue(): bool
	{
		// a bound value carrying SQL words must render as one literal, not as a
		// half-colored fake query
		$highlighted = Subject::sql('UPDATE t SET note = \'select from where\'');
		
		return str_contains($highlighted, '<brown>\'select from where\'<reset>')
			&& substr_count($highlighted, '<cyan>') === 2; // UPDATE, SET
	}
	
	public function sqlColorsNullDistinctlyFromLiterals(): bool
	{
		$highlighted = Subject::sql('INSERT INTO t VALUES (NULL, \'x\')');
		
		return str_contains($highlighted, '<darkpurple>NULL<reset>')
			&& Formatter::stripMarkup($highlighted) === 'INSERT INTO t VALUES (NULL, \'x\')';
	}
	
	public function sqlDoesNotMatchKeywordsInsideIdentifiers(): bool
	{
		$highlighted = Subject::sql('SELECT is_active, ending FROM t');
		
		// is_active / ending contain IS and END but are single words
		return str_contains($highlighted, '<cyan>is<reset>_active') === false
			&& str_contains($highlighted, '<cyan>end<reset>ing') === false;
	}
	
	public function sanitizeStripsInjectedMarkupAndEscapes(): bool
	{
		$highlighted = Subject::sql("SELECT '\33[31m<green>evil'");
		$visible = Formatter::stripMarkup($highlighted);
		
		// the ESC byte goes, so the terminal cannot act; what followed it stays
		// as inert text rather than vanishing, which keeps the value honest in a
		// tool whose whole job is showing what was sent
		return str_contains($highlighted, "\33") === false
			&& $visible === "SELECT '[31mevil'"
			// and no attacker-supplied colour survives to be resolved
			&& str_contains($highlighted, '<green>') === false;
	}
	
	/**
	 * The families the old SGR-only regex let through — every one of these
	 * reached the terminal and acted on it
	 */
	public function sanitizeStripsEveryEscapeFamily(): bool
	{
		$attacks = [
			"\33]52;c;cm0gLXJmIH4gIw==\7", // OSC 52: writes the system clipboard
			"\33]8;;http://evil.example/\7click\33]8;;\7", // OSC 8: hidden link
			"\33]0;pwned\7", // window title
			"\33[?1049h", // alternate screen — '?' is not in [0-9;]
			"\33(0", // line-drawing charset: garbles everything after it
			"\33c", // RIS: full terminal reset
			"\33\33[0mc", // the same reset, assembled by a single-pass strip
			"safe\rEVIL", // CR redraws over what was already printed
			"\33P q\33\\", // DCS
			"\33_apc\33\\", // APC
		];
		
		foreach($attacks as $attack)
		{
			$sanitized = Subject::sanitize($attack);
			
			// no ESC, no BEL, no CR — nothing left that a terminal interprets
			if(preg_match('/[\x00-\x08\x0b-\x1f\x7f]/', $sanitized) === 1)
			{
				return false;
			}
		}
		
		// and the legitimate content a cell carries survives untouched
		return Subject::sanitize("zażółć\tgęślą\njaźń") === "zażółć\tgęślą\njaźń";
	}
	
	public function redisColorsTheVerbAndTheKeys(): bool
	{
		$highlighted = Subject::redis('hGet console:projects 12');
		
		return str_contains($highlighted, '<cyan>HGET<reset>')
			&& str_contains($highlighted, '<green>console:projects<reset>')
			&& str_contains($highlighted, '<gray>12<reset>');
	}
	
	public function redisColorsAKeyWhereverItSits(): bool
	{
		// FCALL reports the function name before the key, so position cannot
		// decide which token is a key — the shape has to
		$highlighted = Subject::redis('FCALL console_cache_clear console:*');
		
		return str_contains($highlighted, '<cyan>FCALL<reset>')
			&& str_contains($highlighted, '<gray>console_cache_clear<reset>')
			&& str_contains($highlighted, '<green>console:*<reset>');
	}
	
	public function redisKeepsItsSpacingAndFindsAWrappedKey(): bool
	{
		// the view wraps at 150 columns BEFORE highlighting, so a key can arrive
		// split across a line break — it used to fail isRedisKey() and both halves
		// went grey
		$wrapped = Subject::redis("HGET console:issues:abc\nproject");
		
		return str_contains($wrapped, '<green>console:issues:abc<reset>')
			// a run of spaces is content, not a delimiter: splitting on a single
			// space made an empty coloured token out of the gap
			&& Subject::redis('GET  foo') === '<cyan>GET<reset>  <gray>foo<reset>'
			// and a leading one used to skip highlighting altogether
			&& Subject::redis(' GET foo') === ' <cyan>GET<reset> <gray>foo<reset>';
	}
	
	public function keywordOrderCannotMaskALongerEntry(): bool
	{
		// alternation is first-match-wins, and the list had ON ahead of
		// ON DUPLICATE KEY, which made the longer entry unmatchable
		$upsert = Subject::sql('INSERT INTO t VALUES (1) ON DUPLICATE KEY UPDATE a = 2');
		
		return str_contains($upsert, '<cyan>ON DUPLICATE KEY<reset>')
			&& str_contains(Subject::sql('SELECT a FOR UPDATE'), '<cyan>FOR UPDATE<reset>')
			&& str_contains(Subject::sql('SELECT a FROM t'), '<cyan>FROM<reset>');
	}
	
	public function emptyInputNeverCarriesMarkup(): bool
	{
		// a cell with nothing in it must not render as a coloured run of padding —
		// color()/tally()/time()/memory() always held to that, these three did not
		return Subject::className('') === ''
			&& Subject::header('') === ''
			&& Subject::messageType('') === ''
			// and a real label still colours
			&& Subject::header('Time') === '<white>Time<reset>';
	}
	
	public function timeAndMemoryEscalateWithTheirThresholds(): bool
	{
		return str_contains(Subject::time('0.00001200'), '<gray>')
			&& str_contains(Subject::time('0.01500000'), '<yellow>')
			&& str_contains(Subject::time('0.42000000'), '<red>')
			&& str_contains(Subject::memory('512 B'), '<gray>')
			&& str_contains(Subject::memory('220.5 KB'), '<yellow>')
			&& str_contains(Subject::memory('3.1 MB'), '<red>')
			&& Subject::time(null) === ''
			&& Subject::memory(null) === '';
	}
	
	public function classNameDimsTheNamespaceAndKeepsTheLeafBright(): bool
	{
		$migration = Subject::className('Migrations\Projects\ProjectGithub');
		$test = Subject::className('Tests\Terminal\ColorSupport::cronStaysPlain');
		
		return $migration === '<gray>Migrations\Projects\<reset><cyan>ProjectGithub<reset>'
			&& $test === '<gray>Tests\Terminal\<reset><cyan>ColorSupport<reset>'
				. '<gray>::<reset><white>cronStaysPlain<reset>'
			// a bare class has no namespace to dim
			&& Subject::className('Migration') === '<cyan>Migration<reset>';
	}
	
	public function headingOnlyMentionsACapThatBit(): bool
	{
		// nothing dropped: naming a limit that took nothing is noise
		return Formatter::stripMarkup(Subject::heading('Queries', 12, 12))
				=== 'Queries (12)'
			&& Formatter::stripMarkup(Subject::heading('Redis', 0, 0)) === 'Redis (0)'
			// dropped: the table is a tail, and 20 would otherwise read as the
			// whole request
			&& Formatter::stripMarkup(Subject::heading('Queries', 20, 137))
				=== 'Queries (last 20 of 137)'
			// a total below the shown count cannot happen, but must not produce
			// "last 20 of 3" if it ever did
			&& Formatter::stripMarkup(Subject::heading('Queries', 20, 3))
				=== 'Queries (20)';
	}
	
	public function tallyStaysQuietAtZero(): bool
	{
		// a colored "0" in an errors column reads as a signal where there is
		// none, so only a real count takes the color
		return Subject::tally('0') === '<gray>0<reset>'
			&& Subject::tally('7') === '<yellow>7<reset>'
			&& Subject::tally('12', 'red') === '<red>12<reset>'
			&& Subject::tally('') === '';
	}
	
	public function colorLeavesEmptyCellsEmpty(): bool
	{
		// an unset column (a NULL rolled_back_at) must not carry markup, or the
		// cell renders as a colored run of padding
		return Subject::color('', 'gray') === ''
			&& Subject::color(null, 'gray') === ''
			&& Subject::color('2026-07-23 18:20:36', 'darkgreen')
				=== '<darkgreen>2026-07-23 18:20:36<reset>';
	}
	
	public function colorNeverSpansALineBreak(): bool
	{
		$highlighted = Subject::sql("SELECT 'a\nb' FROM t");
		
		foreach(explode("\n", $highlighted) as $line)
		{
			// every line closes what it opens, so Table's padding and border
			// stay uncolored
			if(substr_count($line, '<reset>') === 0)
			{
				return false;
			}
		}
		
		return true;
	}
	
	public function wrappedKeywordsStillMatchAcrossTheBreak(): bool
	{
		$highlighted = Subject::sql("SELECT * FROM t GROUP\nBY id");
		
		return str_contains($highlighted, '<cyan>GROUP');
	}
	
	public function coloredCellsStillAlignInATable(): bool
	{
		$sql = 'SELECT id FROM users';
		$plain = (new Table)
			->setHeaders(['Q', 'Time'])
			->addRow([$sql, '0.00001000'])
			->getTable();
		$colored = (new Table)
			->setHeaders([Subject::header('Q'), Subject::header('Time')])
			->addRow([Subject::sql($sql), Subject::time('0.00001000')])
			->getTable();
		
		// same geometry: the markup must not widen a single column
		$plainRule = explode("\n", $plain)[0];
		$coloredRule = explode("\n", $colored)[0];
		
		return $plainRule === $coloredRule
			&& strlen($colored) > strlen($plain);
	}
	
	public function longQueriesStayWithinTheirColumn(): bool
	{
		// a table built the way the profiler view builds it
		$sql = 'SELECT ' . str_repeat('very_long_column_name, ', 20) . 'id FROM users';
		$table = (new Table)
			->addRow([Subject::sql(wordwrap($sql, 60, "\n")), Subject::time('0.00100000')])
			->getTable();
		
		$widths = [];
		foreach(explode("\n", rtrim($table, "\n")) as $line)
		{
			// the table hands its markup on now, so the invisible part to discount
			// is the tokens rather than resolved ANSI
			$widths[] = mb_strwidth(Formatter::stripMarkup($line));
		}
		
		// every rendered line of the box is the same width
		return count(array_unique($widths)) === 1;
	}
}
