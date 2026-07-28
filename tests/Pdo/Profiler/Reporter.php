<?php
declare(strict_types=1);

namespace Tests\Pdo\Profiler;

use Ovos\Measurement;
use Ovos\Pdo\Profiler\Collector;
use Ovos\Pdo\Profiler\Reporter as Subject;
use Ovos\Test;

use function count;
use function str_contains;

/**
 * Reporter - the query text the profiler shows
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Reporter extends Test
{
	public function boundValuesAreQuotedTheWaySqlQuotes(): bool
	{
		$report = $this->report(
			'SELECT * FROM u WHERE name = ? AND city = ?',
			["O'Brien", 'Wien'],
		);
		
		// an apostrophe inside a value used to close its own literal, which put
		// every literal after it in the statement one quote out of step — the
		// rendered query stopped being something you could paste back into a
		// client, and anything reading it as SQL was misled
		return str_contains($report, "name = 'O''Brien'")
			&& str_contains($report, "city = 'Wien'");
	}
	
	public function aNullBindStaysAKeywordRatherThanAString(): bool
	{
		return str_contains($this->report('UPDATE t SET a = ?', [null]), 'a = NULL');
	}
	
	/**
	 * Pushes one query through the collector the profiler reads from, and
	 * returns the sql the report renders for it
	 */
	protected function report(
		string $sql,
		array $parameters,
	): string
	{
		Collector::getInstance()->setQuery($sql,
			$parameters,
			(new Measurement)->start()->stop(),
		);
		
		$report = (new Subject)->getReport() ?? [];
		
		return (string)($report[count($report) - 1]->sql ?? '');
	}
}
