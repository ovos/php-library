<?php
declare(strict_types=1);

namespace Tests\Service\Console;

use Exception;
use Ovos\ArrayObject;
use Ovos\Exception\NotFoundException;
use Ovos\Service\Console\Priority;
use Ovos\Service\Console\Sender as ConsoleSender;
use Ovos\Test;
use Throwable;
use WeakReference;

use function count;
use function gc_collect_cycles;

/**
 * Sender — capture-side queue semantics (no HTTP: the test double
 * neuters send(); flush() is never invoked, so no shutdown coupling).
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Sender extends Test
{
	public function capturesDistinctThrowables(): bool
	{
		$sender = $this->makeSender();
		$first = new Exception('first');
		$second = new Exception('second');
		
		$sender->captureException($first);
		$sender->captureException($second);
		
		return $sender->queueCount() === 2;
	}
	
	/**
	 * Regression: the queue used to key throwables by spl_object_id without
	 * holding the object. Once the caller freed it, PHP recycled the handle
	 * and a later, different exception landed on the same id, silently
	 * overwriting the queued slot. Retention is the fix — and directly
	 * observable through a WeakReference.
	 */
	public function retainsQueuedThrowables(): bool
	{
		$sender = $this->makeSender();
		
		$first = new Exception('first');
		$weak = WeakReference::create($first);
		$sender->captureException($first);
		unset($first); // the caller drops its reference right away
		gc_collect_cycles();
		
		return $weak->get() !== null;
	}
	
	public function dedupesSameThrowableInstance(): bool
	{
		$sender = $this->makeSender();
		$exception = new Exception('boom');
		
		$sender->captureException($exception);
		$sender->captureException($exception);
		
		return $sender->queueCount() === 1;
	}
	
	public function queueIsCapped(): bool
	{
		$sender = $this->makeSender();
		
		for($i = 0; $i < ConsoleSender::QUEUE_MAX + 5; $i++)
		{
			$sender->captureMessage('message ' . $i);
		}
		
		return $sender->queueCount() === ConsoleSender::QUEUE_MAX;
	}
	
	/**
	 * Regression: the flush-time Events merge went through the same cap as
	 * explicit captures, so uncaught throwables were dropped whenever the
	 * queue was already full — exactly the errors that matter most.
	 */
	public function eventsMergeHasHeadroomPastTheCap(): bool
	{
		$sender = $this->makeSender();
		
		for($i = 0; $i < ConsoleSender::QUEUE_MAX; $i++)
		{
			$sender->captureMessage('message ' . $i);
		}
		
		$sender->captureException(new Exception('explicit — capped'));
		$cappedForCaptures = $sender->queueCount() === ConsoleSender::QUEUE_MAX;
		
		$sender->mergeEvent(new Exception('uncaught — must still queue'));
		
		return $cappedForCaptures
			&& $sender->queueCount() === ConsoleSender::QUEUE_MAX + 1;
	}
	
	public function disabledSenderCapturesNothing(): bool
	{
		$sender = $this->makeSender(enabled: false);
		
		$sender->captureException(new Exception('boom'));
		$sender->captureMessage('boom');
		
		return $sender->queueCount() === 0;
	}
	
	public function capture404QueuesAnInfoTypedPayload(): bool
	{
		$sender = $this->makeSender(report404: true);
		
		$sender->capture404('/wp-login.php');
		$payload = $sender->lastPayload();
		
		return $sender->queueCount() === 1
			&& ($payload['type'] ?? null) === '404'
			&& ($payload['priority'] ?? null) === Priority::INFO
			&& ($payload['message'] ?? '') === '404 Not Found: /wp-login.php';
	}
	
	public function capture404DropsTheQueryString(): bool
	{
		$sender = $this->makeSender(report404: true);
		
		// the query is dropped so distinct probes stay distinct and any secret
		// in it never reaches the message
		$sender->capture404('/search?password=secret&q=1');
		
		return ($sender->lastPayload()['message'] ?? '') === '404 Not Found: /search';
	}
	
	public function capture404IsNoopUnlessEnabled(): bool
	{
		$sender = $this->makeSender(report404: false);
		
		$sender->capture404('/wp-login.php');
		
		return $sender->queueCount() === 0;
	}
	
	public function notFoundExceptionBecomesA404WhenEnabled(): bool
	{
		$_SERVER['REQUEST_URI'] = '/xmlrpc.php?rsd';
		$sender = $this->makeSender(report404: true);
		
		// the flush-time Events merge is where framework 404s arrive
		$sender->mergeEvent(new NotFoundException('No route'));
		$payload = $sender->lastPayload();
		
		return ($payload['type'] ?? null) === '404'
			&& ($payload['priority'] ?? null) === Priority::INFO
			&& ($payload['message'] ?? '') === '404 Not Found: /xmlrpc.php';
	}
	
	public function notFoundExceptionStaysAnErrorWhenDisabled(): bool
	{
		$sender = $this->makeSender(report404: false);
		
		$sender->mergeEvent(new NotFoundException('No route'));
		$payload = $sender->lastPayload();
		
		// a normal exception payload: no 404 type override, error priority
		return ($payload['type'] ?? null) === null
			&& ($payload['priority'] ?? null) === Priority::ERROR;
	}
	
	/**
	 * @return ConsoleSender&object{queueCount: callable(): int}
	 */
	protected function makeSender(
		bool $enabled = true,
		bool $report404 = false,
	): ConsoleSender
	{
		$config = new ArrayObject([
			'enabled' => $enabled,
			'url' => 'https://console.invalid',
			'key' => 'test-key',
			'report_404' => $report404,
		]);
		
		return new class($config) extends ConsoleSender
		{
			public function queueCount(): int
			{
				return count($this->queue);
			}
			
			/**
			 * @return array<string, mixed> the most recently queued payload
			 */
			public function lastPayload(): array
			{
				return $this->queue === []
					? []
					: $this->queue[count($this->queue) - 1];
			}
			
			/**
			 * The flush-time Events merge path, byte-for-byte
			 */
			public function mergeEvent(
				Throwable $event,
			): void
			{
				$this->enqueue($this->event()->exception($event), self::QUEUE_MAX * 2);
			}
			
			protected function send(
				string $json,
			): void
			{
				// never post from tests
			}
		};
	}
}
