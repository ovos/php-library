<?php
declare(strict_types=1);

namespace Ovos\Service\Cache;

use Ovos\ArrayObject;
use Ovos\Cache\Store\Apcu;
use Ovos\Cache\MemoLock\Apcu as MemoLock;

/**
 * Perishable
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Perishable
{
	protected ArrayObject $config;
	
	protected ?Apcu $store = null;
	
	protected ?MemoLock $queue = null;
	
	public function __construct(
		ArrayObject $config,
	)
	{
		$this->config = $config;
	}
	
	public function getConfig(): ArrayObject
	{
		return $this->config;
	}
	
	public function getStore(): Apcu
	{
		if($this->store === null)
		{
			$this->store = new Apcu(
				$this->config->prefix,
				$this->config->perishable,
			);
		}
		
		return $this->store;
	}
	
	public function getQueue(): MemoLock
	{
		if($this->queue === null)
		{
			$this->queue = new MemoLock(
				$this->config->prefix,
				$this->config->perishable,
			);
		}
		
		return $this->queue;
	}
}
