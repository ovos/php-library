<?php
declare(strict_types=1);

namespace Ovos;

use DateTime;

/**
 * Date
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Date
{
	/**
	 * @param int $seconds
	 * 
	 * @return string
	 */
	public static function hms(int $seconds): string
	{
		$zero = new DateTime('@0');
		$offset = new DateTime('@' . (string)$seconds);
		$diff = $zero->diff($offset);
		
		return sprintf('%02d:%02d:%02d', $diff->days * 24 + $diff->h, $diff->i, $diff->s);
	}

	/**
	 * @param string $month (this, current, previous, next, 0, 1, 2, -1, -2, ...)
	 * 
	 * @return DateTime
	 */
	public static function getMonthStart(string $month): DateTime
	{
		$start = new DateTime;
		$start
			->modify('first day of ' . $month . ' month')
			->setTime(0, 0, 0, 0);
			
		return $start;
	}

	/**
	 * @param DateTime $start
	 * 
	 * @return DateTime
	 */
	public static function getMonthEnd(DateTime $start): DateTime
	{
		$end = clone $start;
		$end->modify('last day of this month')
			->setTime(23, 59, 59, 999999);
			
		return $end;	
	}

	/**
	 * @param string $week (this, current, previous, next, 0, 1, 2, -1, -2, ...)
	 * 
	 * @return DateTime
	 */
	public static function getWeekStart(string $week): DateTime
	{
		$start = new DateTime;
		$start
			->modify('monday ' . $week . ' week')
			->setTime(0, 0, 0, 0);
			
		return $start;
	}

	/**
	 * @param DateTime $start
	 * 
	 * @return DateTime
	 */
	public static function getWeekEnd(DateTime $start): DateTime
	{
		$end = clone $start;
		$end->modify('sunday this week')
			->setTime(23, 59, 59, 999999);
			
		return $end;	
	}
}
