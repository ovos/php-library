<?php
declare(strict_types=1);

namespace Ovos\Cache;

use Ovos\ArrayObject;
use Ovos\Client;
use Ovos\Invoker;
use Closure;
use DateTime;
use Exception;
use Throwable;

use function date;
use function file_put_contents;
use function sprintf;
use function substr;
use function bin2hex;
use function random_bytes;

use const LOGS_DIR;

/**
 * MemoLock
 *
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class MemoLock
{
	protected Invoker $invoker;
	protected Prefixer $prefixer;
	
	// queue
	protected bool $queueEnabled = true;
	
	// queue debug
	protected bool $debugEnabled = false;
	protected bool $debugTimeouts = false;
	protected string $debugFilename = 'memolock_debug';
	
	/**
	 * An array of unique values for any active locks,
	 * indexed by the prefixed cache id
	 */
	protected array $queueLocks = [];
	
	public function __construct(
		?ArrayObject $config = null,
	)
	{
		$this->configure($config);
	}
	
	public function configure(?ArrayObject $config = null): static
	{
		if($config === null)
		{
			return $this;
		}
		
		if(($queue = $config->offsetGet('queue')) !== null)
		{
			$this->setQueue($queue);
			
			if(($debug = $queue->offsetGet('debug')) !== null)
			{
				$this->setDebug($debug);
			}
		}
		
		return $this;
	}
	
	abstract public function setQueue(
		ArrayObject $config,
	): static;
	
	public function setDebug(
		ArrayObject $config,
	): static
	{
		if(($enabled = $config->offsetGet('enabled')) !== null)
		{
			$this->debugEnabled = $enabled;
		}
		if(($timeouts = $config->offsetGet('timeouts')) !== null)
		{
			$this->debugTimeouts = $timeouts;
		}
		if(($filename = $config->offsetGet('filename')) !== null)
		{
			$this->debugFilename = $filename;
		}
		
		return $this;
	}
	
	public function setQueueEnabled(
		bool $enabled,
	): static
	{
		$this->queueEnabled = $enabled;
		
		return $this;
	}
	
	public function isQueueEnabled(): bool
	{
		return $this->queueEnabled;
	}
	
	public function setDebugEnabled(
		bool $enabled,
	): static
	{
		$this->debugEnabled = $enabled;
		
		return $this;
	}
	
	public function isDebugEnabled(): bool
	{
		return $this->debugEnabled;
	}
	
	public function getPrefixer(): Prefixer
	{
		return $this->prefixer;
	}
	
	/**
	 * $lockAndLook takes the lock and makes the second look in one round
	 * trip - fn(string $lockKey, string $lockValue, int $ttlMs):
	 * array{0: bool, 1: mixed}, whether the lock was taken and, when it was,
	 * what the look found; the first attempt uses it instead of a plain lock
	 * and the $fetcher (a store that can pipeline its read with the lock)
	 */
	abstract public function lockAndQueue(
		string $id,
		?Closure $fetcher = null,
		?Closure $resolver = null,
		?bool $queue = null,
		?int $queueLockTtlMs = null,
		?Closure $lockAndLook = null,
	): mixed;
	
	abstract public function releaseActiveLock(
		string $id,
	): bool;
	
	abstract public function renewLock(
		string $id,
	): bool;
	
	/**
	 * Takes the lock when it is free, without waiting: true when this
	 * process holds it now (release it with releaseActiveLock()), false when
	 * another holds it. Elects the one process that refreshes a stale value
	 * (KeyValue::get(stale:)) while the others serve it; nobody waits, so a
	 * queue switched off does not change it
	 */
	abstract public function tryLock(
		string $id,
		?int $queueLockTtlMs = null,
	): bool;
	
	/**
	 * The lock is this process's: it computes - unless the value landed
	 * meanwhile (double-checked locking). A process whose miss came just
	 * before the holder wrote and released, and whose subscription missed the
	 * publication, takes the free lock next; without the second look it
	 * computes the value again (the lock orders the computing, not the
	 * reading - Benchmarks\Cache\Store\MemoLock\RedisCluster counted two
	 * resolutions). One read more, for the holder only - made with the lock,
	 * in its round trip, when the store could ($looked: $look is what it
	 * found); a lock-only call (no fetcher) computes as before
	 */
	protected function lockAcquired(
		string $id,
		string $lockValue,
		?Closure $resolver = null,
		?Closure $fetcher = null,
		bool $looked = false,
		mixed $look = null,
	): mixed
	{
		$this->queueLocks[$id] = $lockValue;
		
		// inside the try: a fetcher that throws releases the lock at once, not at
		// its TTL (the waiters would stall until then)
		try
		{
			// the second look: the value may have landed between this process's
			// miss and its lock - the holder wrote and released just before, and
			// its publication came before our subscription; computing now would
			// duplicate the work the lock exists to save
			$result = $looked
				? $look
				: ($fetcher === null ? null : $this->invoker->invoke($fetcher));
			if($result !== null)
			{
				// released, so the waiters are told (and read it)
				$this->releaseActiveLock($id);
				
				return $result;
			}
			
			return $this->invoker
				->invoke($resolver);
		}
		catch(Throwable $throwable)
		{
			$this->releaseActiveLock($id);
			
			throw $throwable;
		}
	}
	
	public function debug(
		string $message,
		bool $trace = false,
		bool $timeout = false,
	): static
	{
		if($this->debugEnabled === false
			&& ($this->debugTimeouts === true
				&& $timeout === true) === false
		)
		{
			return $this;
		}
		
		$filename = sprintf('%s_%s.txt',
			$this->debugFilename,
			date('Y_m_d'),
		);
		
		static $requestId = null;
		if($requestId === null)
		{
			$requestId = substr(bin2hex(
				random_bytes(4)
			), 0, 8);
		}
		
		$message = sprintf(
			'%s [%s] %s: %s' . PHP_EOL,
			Client::getIp(),
			$requestId,
			(new DateTime)->format('Y-m-d H:i:s.u'),
			$message,
		);
		
		if($trace)
		{
			$backtrace = new Exception;
			$message.= $backtrace->getTraceAsString() . PHP_EOL;
		}
		
		file_put_contents(LOGS_DIR . $filename,
			$message
		, FILE_APPEND);
		
		return $this;
	}
}
