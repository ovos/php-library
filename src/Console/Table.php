<?php
declare(strict_types=1);

namespace Ovos\Console;

use Ovos\Terminal\Formatter;
use Override;
use Console_Table;

use function count;
use function is_array;
use function is_string;

/**
 * Table
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Table extends Console_Table
{
	protected bool $hasMarkup = false;
	
	public function hasMarkup(
		bool $hasMarkup,
	): static
	{
		$this->hasMarkup = $hasMarkup;
		
		return $this;
	}
	
	/**
	 * Calculates the maximum length for each column of a row.
	 */
	#[Override] 
	function _calculateCellLengths(
		$row, // the row data
	)
	{
		if(is_array($row) === false)
		{
			return;
		}
		
		for($i = 0,
			$iMax = count($row); $i < $iMax; $i++)
		{
			if(!isset($this->_cell_lengths[$i]))
			{
				$this->_cell_lengths[$i] = 0;
			}
			$rowValue = $this->hasMarkup && is_string($row[$i])
				? Formatter::stripTerminalMarkup($row[$i])
				: $row[$i];
			
			$this->_cell_lengths[$i] = max($this->_cell_lengths[$i],
				$this->_strlen($rowValue));
		}
	}
	
	/**
	 * Returns the character length of a string.
	 */
	#[Override]
	function _strlen(
		$str, // a multibyte or singlebyte string.
	)
	{
		$str = $this->hasMarkup && is_string($str)
			? Formatter::stripTerminalMarkup($str)
			: $str;
		
		return parent::_strlen($str);
	}
}
