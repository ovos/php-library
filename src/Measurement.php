<?php
declare(strict_types=1);

namespace Ovos;

use stdClass;

use function microtime;
use function memory_get_usage;

/**
 * Measurement
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Measurement
{
	/**
	 * @var stdClass
	 */
	protected stdClass $_time;
	
	/**
	 * @var stdClass
	 */
	protected stdClass $_memory;
	
	/**
	 */
	public function __construct()
	{
		$this->_time = new stdClass;
		$this->_memory = new stdClass;
	}
	
	/**
	 * @return self
	 */
	public function start(): self
	{
		$this->_time->start = microtime(true);
		$this->_memory->start = memory_get_usage(false);
		
		return $this;
	}
	
	/**
	 * @return self
	 */
	public function stop(): self
	{
		$this->_time->end = microtime(true);
		$this->_memory->end = memory_get_usage(false);
		
		return $this;
	}
	
	/**
	 * @return ?string
	 */
	public function getTotalTime(): ?string
	{
		if(isset($this->_time->total) === false)
		{
			if(isset($this->_time->start, $this->_time->end) === false)
			{
				return null;
			}
			
			$this->_time->total = Measurements::formatTime(
				$this->_time->start,
				$this->_time->end);
		}
	
		return $this->_time->total;
	}
	
	/**
	 * @return ?string
	 */
	public function getTotalMemory(): ?string
	{
		if(isset($this->_memory->total) === false)
		{
			if(isset($this->_memory->start, $this->_memory->end) === false)
			{
				return null;
			}
			
			$this->_memory->total = Measurements::formatMemory(
				$this->_memory->start,
				$this->_memory->end);
		}
		
		return $this->_memory->total;
	}
}
