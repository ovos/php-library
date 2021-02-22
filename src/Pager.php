<?php
declare(strict_types=1);

namespace Ovos;

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
	protected int $_page;

	/**
	 * @var int
	 */
	protected int $_count;

	/**
	 * @var int
	 */
	protected int $_perPage;

	/**
	 * @var int
	 */
	protected int $_pages;

	/**
	 * @param int $page
	 * @param int $count
	 * @param int $perPage
	 */
	public function __construct(int $page, int $count, int $perPage)
	{
		$this->_count = $count;
		$this->_perPage = $perPage;
		$this->_pages = (int)ceil($count / $perPage);
		$this->_page = ($page <= 0 || $page > $this->_pages) ? 1 : $page;
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
	 * @return int
	 */
	public function getPage(): int
	{
		return $this->_page;
	}

	/**
	 * @return bool
	 */
	public function hasNext(): bool
	{
		return $this->_page < $this->_pages;
	}

	/**
	 * @return int
	 */
	public function getNext(): int
	{
		return $this->_page + 1;
	}

	/**
	 * @return bool
	 */
	public function hasPrevious(): bool
	{
		return $this->_page > 1;
	}

	/**
	 * @return int
	 */
	public function getPrevious(): int
	{
		return $this->_page - 1;
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
