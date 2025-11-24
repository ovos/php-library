<?php
declare(strict_types=1);

namespace Ovos;

use function ceil;
use function floor;
use function min;
use function max;
use function range;

/**
 * Pager
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Pager
{
	/**
	 * @var int
	 */
	protected int $_count;
	
	/**
	 * @var int
	 */
	protected int $_firstPage = 1;
	
	/**
	 * @var int
	 */
	protected int $_page;
	
	/**
	 * @var int
	 */
	protected int $_perPage;
	
	/**
	 * @var int
	 */
	protected int $_pages;
	
	/**
	 * @var int
	 */
	protected int $_pageRange;
	
	/**
	 * @param int $page
	 * @param int $count
	 * @param int $perPage
	 * @param int $pageRange
	 */
	public function __construct(
		int $page,
		int $count,
		int $perPage,
		int $pageRange = 10,
	)
	{
		$this->_count = $count;
		$this->_perPage = $perPage;
		$this->_pages = max(1, (int)ceil($count / $perPage));
		$this->_page = ($page <= 0 || $page > $this->_pages)
			? $this->_firstPage
			: $page;
		$this->_pageRange = $pageRange;
	}
	
	/**
	 * @return int
	 */
	public function getCount(): int
	{
		return $this->_count;
	}
	
	/**
	 * @return int
	 */
	public function getPerPage(): int
	{
		return $this->_perPage;
	}
	
	/**
	 * @return int
	 */
	public function getPages(): int
	{
		return $this->_pages;
	}
	
	/**
	 * @return array
	 */
	public function getPagesInRange(): array
	{
		$halfRange = (int)floor($this->_pageRange / 2);
		$start = max(1, $this->_page - $halfRange);
		$end = min($this->_pages, $this->_page + $halfRange);
		
		// adjust if there are fewer pages to the left
		if($this->_page - $start < $halfRange)
		{
			$end = min($this->_pages, $end + ($halfRange - ($this->_page - $start)));
		}
		
		// adjust if there are fewer pages to the right
		if($end - $this->_page < $halfRange)
		{
			$start = max(1, $start - ($halfRange - ($end - $this->_page)));
		}
		
		return range($start, $end);
	}
	
	/**
	 * @return int
	 */
	public function getPage(): int
	{
		return $this->_page;
	}
	
	/**
	 * @return bool
	 */
	public function hasNextPage(): bool
	{
		return $this->_page < $this->_pages;
	}
	
	/**
	 * @return int
	 */
	public function getNextPage(): int
	{
		return $this->_page + 1;
	}
	
	/**
	 * @return bool
	 */
	public function hasPreviousPage(): bool
	{
		return $this->_page > 1;
	}
	
	/**
	 * @return int
	 */
	public function getPreviousPage(): int
	{
		return $this->_page - 1;
	}
	
	/**
	 * @param int $pageRange
	 *
	 * @return static
	 */
	public function setPageRange(int $pageRange): static
	{
		$this->_pageRange = $pageRange;
		
		return $this;
	}
	
	/**
	 * @return int
	 */
	public function getPageRange(): int
	{
		return $this->_pageRange;
	}
	
	/**
	 * @return int
	 */
	public function getFirstPage(): int
	{
		return $this->_firstPage;
	}
	
	/**
	 * @return int
	 */
	public function getLastPage(): int
	{
		return $this->getPages();
	}
	
	/**
	 * @return bool
	 */
	public function isFirstPage(): bool
	{
		return $this->_page === $this->_firstPage;
	}
	
	/**
	 * @return bool
	 */
	public function isLastPage(): bool
	{
		return $this->_page === $this->_pages;
	}
	
	/**
	 * @return int
	 */
	public function getOffset(): int
	{
		return $this->_perPage * $this->_page - $this->_perPage;
	}
	
	/**
	 * @return int
	 */
	public function getLimit(): int
	{
		return $this->getPerPage();
	}
	
	/**
	 * @return int
	 */
	public function getFirst(): int
	{
		return $this->getOffset() + 1;
	}
	
	/**
	 * @return int
	 */
	public function getLast(): int
	{
		$last = $this->_perPage * $this->_page;
		if($last > $this->_count)
		{
			return $this->_count;
		}
		
		return $last;
	}
}
