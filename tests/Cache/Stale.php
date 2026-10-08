<?php
declare(strict_types=1);

namespace Tests\Cache;

use Ovos\Cache\Stale as Subject;
use Ovos\Test;

use function microtime;
use function serialize;
use function str_contains;
use function unserialize;

use const INF;

/**
 * Stale - the record a value written with a stale time is stored as. Its
 * stale window is its own (fresh until freshUntil, servable staleFor
 * seconds past it, kept errorFor seconds more for a computation that
 * throws), whatever the store's TTL; a soft invalidation is the store's
 * verdict at read time, never part of what is stored. Stale::wrap() is the
 * one place that decides which value is wrapped, and how
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Stale extends Test
{
	/**
	 * RULE: the invalidated mark is a read's verdict - a record serializes
	 * without it and reads back unmarked
	 */
	public function theInvalidatedMarkIsNotStored(): bool
	{
		$stored = serialize((new Subject('value', microtime(true) + 60, 30, true))->invalidated());
		$read = unserialize($stored);
		
		return str_contains($stored, 'invalidated') === false
			&& $read instanceof Subject
			&& $read->invalidated === false
			&& $read->soft === true
			&& $read->staleFor === 30;
	}
	
	/**
	 * RULE: a mark stored with a record (as one written before the mark was
	 * left out could carry) is ignored - a read decides it
	 */
	public function aStoredMarkIsIgnored(): bool
	{
		$read = unserialize('O:16:"Ovos\\Cache\\Stale":6:{s:5:"value";s:5:"value";s:10:"freshUntil";d:1.5;'
			. 's:8:"staleFor";i:30;s:4:"soft";b:1;s:8:"errorFor";i:0;s:11:"invalidated";b:1;}');
		
		return $read instanceof Subject
			&& $read->invalidated === false
			&& $read->value === 'value'
			&& $read->staleFor === 30;
	}
	
	/**
	 * RULE: an invalidated record is servable past its own stale time - the
	 * store judged the window of the invalidation, which may start after it
	 */
	public function anInvalidatedRecordIsServablePastItsOwnStaleTime(): bool
	{
		$record = new Subject('value', microtime(true) - 100, 30, true);
		
		return $record->isServable() === false
			&& $record->invalidated()->isServable() === true;
	}
	
	/**
	 * RULE: an invalidated record keeps its fresh time - it is not fresh, it
	 * is servable (the store judged its window)
	 */
	public function anInvalidatedRecordKeepsItsFreshTime(): bool
	{
		$record = (new Subject('value', microtime(true) + 600, 30, true))->invalidated();
		
		return $record->freshUntil > microtime(true)
			&& $record->invalidated === true
			&& $record->isFresh() === false
			&& $record->isServable() === true;
	}
	
	/**
	 * RULE: a record is servable inside its stale time only - with or without
	 * a time kept for errors; the store's TTL no longer decides
	 */
	public function aRecordIsServableInsideItsStaleTimeOnly(): bool
	{
		$now = microtime(true);
		
		return (new Subject('value', $now - 10, 60))->isServable() === true
			&& (new Subject('value', $now - 10, 5))->isServable() === false
			&& (new Subject('value', $now - 10, 5, errorFor: 60))->isServable() === false
			&& (new Subject('value', $now + 10, 0))->isServable() === true;
	}
	
	/**
	 * RULE: a record is kept for errors past its fresh time, until its stale
	 * time and the time kept for errors are over - never when it was written
	 * without one, never while it is fresh
	 */
	public function aRecordIsKeptForErrorsUntilItsTimeIsOver(): bool
	{
		$now = microtime(true);
		
		return (new Subject('value', $now - 10, 5, errorFor: 60))->isKeptForErrors() === true
			&& (new Subject('value', $now - 10, 60, errorFor: 60))->isKeptForErrors() === true
			&& (new Subject('value', $now - 100, 5, errorFor: 60))->isKeptForErrors() === false
			&& (new Subject('value', $now + 10, 5, errorFor: 60))->isKeptForErrors() === false
			&& (new Subject('value', $now - 10, 5))->isKeptForErrors() === false
			&& (new Subject('value', $now - 100, 5, true, 60))->invalidated()->isKeptForErrors() === true;
	}
	
	/**
	 * RULE: a record is soft only with a stale time - there is no window to
	 * serve it in otherwise
	 */
	public function aRecordIsSoftOnlyWithAStaleTime(): bool
	{
		return (new Subject('value', INF, 30, true))->isSoft() === true
			&& (new Subject('value', INF, 0, true))->isSoft() === false
			&& (new Subject('value', INF, 30, false))->isSoft() === false;
	}
	
	/**
	 * RULE: wrap() keeps a value plain without a stale time or a time kept for
	 * errors; with a TTL it wraps it, its TTL extended by both
	 */
	public function wrapWrapsWithATtlAndExtendsIt(): bool
	{
		$plain = Subject::wrap('value', 60, 0);
		[$record, $ttl] = Subject::wrap('value', 60, 30, false, 100);
		$before = microtime(true);
		
		return $plain === ['value', 60]
			&& $record instanceof Subject
			&& $ttl === 190
			&& $record->value === 'value'
			&& $record->staleFor === 30
			&& $record->errorFor === 100
			&& $record->soft === false
			&& $record->freshUntil > $before + 59
			&& $record->freshUntil <= $before + 60;
	}
	
	/**
	 * RULE: wrap() makes a value soft only with a stale time; without a TTL a
	 * soft value is fresh until an invalidation, and the time kept for errors
	 * needs a TTL
	 */
	public function wrapDecidesSoftAndTheTimeKeptForErrors(): bool
	{
		[$softNoStale] = Subject::wrap('value', 60, 0, true, 100);
		[$untilInvalidated, $ttl] = Subject::wrap('value', 0, 30, true);
		
		return $softNoStale instanceof Subject
			&& $softNoStale->soft === false
			&& $untilInvalidated instanceof Subject
			&& $untilInvalidated->freshUntil === INF
			&& $untilInvalidated->soft === true
			&& $ttl === 0
			&& Subject::wrap('value', 0, 30) === ['value', 0]
			&& Subject::wrap('value', 0, 0, false, 60) === ['value', 0]
			&& Subject::wrap(['a' => 1], 0, 0) === [['a' => 1], 0]
			&& Subject::wrap('value', -5, -1) === ['value', -5];
	}
}
