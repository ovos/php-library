<?php
declare(strict_types=1);

namespace Ovos;

use function ceil;
use function floor;
use function max;
use function min;
use function range;

/**
 * Pager
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Pager
{
	protected int $count;
	
	protected int $firstPage = 1;
	
	protected int $page;
	
	protected int $perPage;
	
	protected int $pages;
	
	protected int $pageRange;
	
	public function __construct(
		int $page,
		int $count,
		int $perPage,
		int $pageRange = 10,
	)
	{
		$this->count = $count;
		$this->perPage = $perPage;
		$this->pages = max(1, (int)ceil($count / $perPage));
		$this->page = ($page <= 0 || $page > $this->pages)
			? $this->firstPage
			: $page;
		$this->pageRange = $pageRange;
	}
	
	public function getCount(): int
	{
		return $this->count;
	}
	
	public function getPerPage(): int
	{
		return $this->perPage;
	}
	
	public function getPages(): int
	{
		return $this->pages;
	}
	
	public function getPagesInRange(): array
	{
		$halfRange = (int)floor($this->pageRange / 2);
		$start = max(1, $this->page - $halfRange);
		$end = min($this->pages, $this->page + $halfRange);
		
		// adjust if there are fewer pages to the left
		if($this->page - $start < $halfRange)
		{
			$end = min(
				$this->pages,
			$end + ($halfRange - ($this->page - $start)),
			);
		}
		
		// adjust if there are fewer pages to the right
		if($end - $this->page < $halfRange)
		{
			$start = max(
				1,
				$start - ($halfRange - ($end - $this->page))
			);
		}
		
		return range($start, $end);
	}
	
	public function getPage(): int
	{
		return $this->page;
	}
	
	public function hasNextPage(): bool
	{
		return $this->page < $this->pages;
	}
	
	public function getNextPage(): int
	{
		return $this->page + 1;
	}
	
	public function hasPreviousPage(): bool
	{
		return $this->page > 1;
	}
	
	public function getPreviousPage(): int
	{
		return $this->page - 1;
	}
	
	public function setPageRange(
		int $pageRange,
	): static
	{
		$this->pageRange = $pageRange;
		
		return $this;
	}
	
	public function getPageRange(): int
	{
		return $this->pageRange;
	}
	
	public function getFirstPage(): int
	{
		return $this->firstPage;
	}
	
	public function getLastPage(): int
	{
		return $this->getPages();
	}
	
	public function isFirstPage(): bool
	{
		return $this->page === $this->firstPage;
	}
	
	public function isLastPage(): bool
	{
		return $this->page === $this->pages;
	}
	
	public function getOffset(): int
	{
		return $this->perPage * $this->page - $this->perPage;
	}
	
	public function getLimit(): int
	{
		return $this->getPerPage();
	}
	
	public function getFirst(): int
	{
		return $this->getOffset() + 1;
	}
	
	public function getLast(): int
	{
		$last = $this->perPage * $this->page;
		if($last > $this->count)
		{
			return $this->count;
		}
		
		return $last;
	}
}
