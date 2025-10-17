<?php
declare(strict_types=1);

namespace Ovos\Store\Cache;

use Ovos\ArrayObject;

/**
 * Cache
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Queue extends Tags
{
	/**#@+
	 * Queue (MemoLock) configuration
	 */
	
	/**
	 * @var bool
	 */ 
	protected bool $_queueEnabled = false;
	
	/**
	 * @var int
	 */
	protected int $_queueLockTtlMs = 5000;
	
	/**
	 * @var int
	 */
	protected int $_queueWaitAttempts = 3;
	
	/**#@-*/
	
	/**
	 * @param ArrayObject $config
	 *
	 * @return self
	 */
	public function setQueue(ArrayObject $config): self
	{
		if(($enabled = $config->offsetGet('enabled')) !== null) // true or false
		{
			$this->_queueEnabled = $enabled;
		}
		if(($lockTtlMs = $config->offsetGet('lock_ttl_ms')) !== null)
		{
			$this->_queueLockTtlMs = $lockTtlMs;
		}
		if(($waitAttempts = $config->offsetGet('wait_attempts')) !== null)
		{
			$this->_queueWaitAttempts = $waitAttempts;
		}
		
		return $this;
	}
	
	/**
	 * @param string $key
	 * @param ?SetCallback $set
	 * @param bool $queue
	 * @param ?int $queueLockTtlMs
	 *
	 * @return null|mixed
	 */
	abstract public function get(
		string $key,
		?SetCallback $set = null,
		bool $queue = false,
		?int $queueLockTtlMs = null,
	): mixed;
}
