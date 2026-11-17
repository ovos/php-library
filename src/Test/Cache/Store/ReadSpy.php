<?php
declare(strict_types=1);

namespace Ovos\Test\Cache\Store;

use Closure;

/**
 * ReadSpy
 *
 * A cached value that tells when a store reads it back: every read
 * unserializes it, and each time it calls $onRead with the number of reads so
 * far. A test uses it to do, between two reads one get() makes, what another
 * process would do meanwhile - between the first read and the look a process
 * takes after it waited for the lock (TraitCachePolicy)
 *
 * @author Marcin Gil <mg@ovos.at>
 */
final class ReadSpy
{
	/**
	 * Called with the number of reads so far, this one included
	 */
	public static ?Closure $onRead = null;
	
	public static int $reads = 0;
	
	public function __construct(
		public string $value,
	)
	{
	}
	
	public static function watch(
		?Closure $onRead,
	): void
	{
		static::$onRead = $onRead;
		static::$reads = 0;
	}
	
	public function __wakeup(): void
	{
		static::$reads++;
		if(static::$onRead !== null)
		{
			(static::$onRead)(static::$reads);
		}
	}
}
