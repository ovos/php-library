<?php
declare(strict_types=1);

namespace Ovos\Console;

use Ovos\Terminal\Formatter;
use Override;
use Console_Table;

/**
 * Table
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Table extends Console_Table
{
	/**
	 * @var bool
	 */
	protected bool $_hasMarkup = false;
	
	/**
	 * @param bool $hasMarkup
	 * 
	 * @return static
	 */
	public function hasMarkup(bool $hasMarkup): static
	{
		$this->_hasMarkup = $hasMarkup;
		
		return $this;
	}
	
	/**
	 * Calculates the maximum length for each column of a row.
	 *
	 * @param array $row The row data.
	 *
	 * @return void
	 */
	#[Override] 
	function _calculateCellLengths($row)
	{
		if(is_array($row))
		{
			for($i = 0; $i < count($row); $i++)
			{
				if(!isset($this->_cell_lengths[$i]))
				{
					$this->_cell_lengths[$i] = 0;
				}
				$rowValue = $this->_hasMarkup && is_string($row[$i])
					? Formatter::stripTerminalMarkup($row[$i]) : $row[$i];
				
				$this->_cell_lengths[$i] = max($this->_cell_lengths[$i],
					$this->_strlen($rowValue));
			}
		}
	}
	
	/**
	 * Returns the character length of a string.
	 *
	 * @param string $str A multibyte or singlebyte string.
	 *
	 * @return integer  The string length.
	 */
	#[Override]
	function _strlen($str)
	{
		$str = $this->_hasMarkup && is_string($str)
			? Formatter::stripTerminalMarkup($str) : $str;
		
		return parent::_strlen($str);
	}
}
