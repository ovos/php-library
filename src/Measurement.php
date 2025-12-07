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
	protected stdClass $time;
	
	protected stdClass $memory;
	
	public function __construct()
	{
		$this->time = new stdClass;
		$this->memory = new stdClass;
	}
	
	public function start(): static
	{
		$this->time->start = microtime(true);
		$this->memory->start = memory_get_usage(false);
		
		return $this;
	}
	
	public function stop(): static
	{
		$this->time->end = microtime(true);
		$this->memory->end = memory_get_usage(false);
		
		return $this;
	}
	
	public function getTotalTime(): ?string
	{
		if(isset($this->time->total) === false)
		{
			if(isset($this->time->start, $this->time->end) === false)
			{
				return null;
			}
			
			$this->time->total = Measurements::formatTime(
				$this->time->start,
				$this->time->end,
			);
		}
	
		return $this->time->total;
	}
	
	public function getTotalMemory(): ?string
	{
		if(isset($this->memory->total) === false)
		{
			if(isset($this->memory->start, $this->memory->end) === false)
			{
				return null;
			}
			
			$this->memory->total = Measurements::formatMemory(
				$this->memory->start,
				$this->memory->end,
			);
		}
		
		return $this->memory->total;
	}
}
