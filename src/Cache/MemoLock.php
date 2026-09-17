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
use function ini_get;
use function is_float;
use function is_int;
use function microtime;

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
	 * Where max_execution_time ends, as far as a wait is concerned: a second
	 * before the limit, so that a waiter still has time to produce the value
	 * itself instead of being killed mid-wait with nothing to serve
	 * Null when there is no limit, or when that moment is already past - the
	 * timer then counts CPU time (Linux) or was restarted by set_time_limit()
	 * and says nothing about the clock
	 * The limit and the start default to max_execution_time and the request
	 * time; tests pass their own
	 */
	public static function deadline(
		?int $limitS = null,
		?float $startedAt = null,
	): ?float
	{
		$limitS ??= (int)ini_get('max_execution_time');
		if($limitS <= 0)
		{
			return null;
		}
		
		if($startedAt === null)
		{
			$started = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
			$startedAt = is_float($started) || is_int($started)
				? (float)$started
				: microtime(true);
		}
		
		$until = $startedAt + $limitS - 1.0;
		
		return microtime(true) < $until
			? $until
			: null;
	}
	
	abstract public function lockAndQueue(
		string $id,
		?Closure $fetcher = null,
		?Closure $resolver = null,
		?bool $queue = null,
		?int $queueLockTtlMs = null,
	): mixed;
	
	abstract public function releaseActiveLock(
		string $id,
	): bool;
	
	abstract public function renewLock(
		string $id,
	): bool;
	
	protected function lockAcquired(
		string $id,
		string $lockValue,
		?Closure $resolver = null,
	): mixed
	{
		$this->queueLocks[$id] = $lockValue;
		
		try
		{
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
