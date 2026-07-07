<?php
declare(strict_types=1);

namespace Tests;

use Ovos\Pager as Subject;
use Ovos\Test;

/**
 * Pager - the pagination math behind every grid/listing
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Pager extends Test
{
	public function computesPageCountByCeiling(): bool
	{
		return (new Subject(1, 95, 10))->getPages() === 10 // 9.5 -> 10
			&& (new Subject(1, 100, 10))->getPages() === 10
			&& (new Subject(1, 0, 10))->getPages() === 1; // never below one
	}
	
	public function clampsAnOutOfRangePageToTheFirst(): bool
	{
		return (new Subject(0, 100, 10))->getPage() === 1
			&& (new Subject(-5, 100, 10))->getPage() === 1
			&& (new Subject(999, 100, 10))->getPage() === 1
			&& (new Subject(3, 100, 10))->getPage() === 3;
	}
	
	public function offsetAndLimitDriveTheQuery(): bool
	{
		$pager = new Subject(3, 100, 10); // page 3, 10 per page
		
		return $pager->getOffset() === 20
			&& $pager->getLimit() === 10
			&& $pager->getFirst() === 21
			&& $pager->getLast() === 30;
	}
	
	public function lastPageClampsTheHighItemToTheCount(): bool
	{
		$pager = new Subject(3, 25, 10); // 25 items, page 3 = items 21..25
		
		return $pager->getFirst() === 21
			&& $pager->getLast() === 25 // not 30
			&& $pager->isLastPage() === true
			&& $pager->hasNextPage() === false;
	}
	
	public function neighbourFlags(): bool
	{
		$first = new Subject(1, 100, 10);
		$middle = new Subject(5, 100, 10);
		$last = new Subject(10, 100, 10);
		
		return $first->isFirstPage() === true
			&& $first->hasPreviousPage() === false
			&& $first->hasNextPage() === true
			&& $middle->getPreviousPage() === 4
			&& $middle->getNextPage() === 6
			&& $last->isLastPage() === true
			&& $last->hasNextPage() === false;
	}
	
	public function rangeStaysCenteredAndWithinBounds(): bool
	{
		// a window of 10 around page 20 of 100
		$middle = (new Subject(20, 1000, 10, 10))->getPagesInRange();
		// near the start it must not underflow below 1
		$start = (new Subject(1, 1000, 10, 10))->getPagesInRange();
		// near the end it must not overflow past the last page
		$end = (new Subject(100, 1000, 10, 10))->getPagesInRange();
		
		return count($middle) === 11 // page +/- 5 inclusive
			&& $middle[0] === 15
			&& $middle[count($middle) - 1] === 25
			&& $start[0] === 1
			&& $end[count($end) - 1] === 100;
	}
}
