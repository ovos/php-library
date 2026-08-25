<?php
declare(strict_types=1);

namespace Tests\Service\Console;

use ErrorException;
use Exception;
use Ovos\ArrayObject;
use Ovos\Exception\NotFoundException;
use Ovos\Exception\Priority;
use Ovos\Service\Console\Sender as ConsoleSender;
use Ovos\Test;
use Throwable;
use WeakReference;

use function count;
use function gc_collect_cycles;

use const E_WARNING;

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
	
	/**
	 * reportRefusal — the security-event channel's sender half. The KIND
	 * travels as the event's className (what the console indexes, filters
	 * and fingerprints by), the human line as the message, and the priority
	 * is INFO by definition (the console pins it there anyway). Placing the
	 * call is the app-side opt-in; there is deliberately no config switch.
	 */
	public function reportRefusalQueuesASecurityTypedPayload(): bool
	{
		$sender = $this->makeSender();
		
		$sender->reportRefusal('auth_failure', 'login failed for m***');
		$payload = $sender->lastPayload();
		
		return $sender->queueCount() === 1
			&& ($payload['type'] ?? null) === 'security'
			&& ($payload['priority'] ?? null) === Priority::INFO
			&& ($payload['message'] ?? '') === 'login failed for m***'
			&& ($payload['events'][0]['className'] ?? null) === 'auth_failure'
			&& ($payload['events'][0]['message'] ?? null) === 'login failed for m***';
	}
	
	/**
	 * Three silences, each load-bearing: a kind outside the closed
	 * vocabulary is a no-op (the console refuses it wholesale, so sending
	 * would only waste the request — and a typo'd kind must not invent a
	 * category); the rolling cap turns a credential-stuffing wave into at
	 * most SECURITY_MAX_PER_MINUTE reports; a disabled sender reports
	 * nothing at all.
	 */
	public function reportRefusalRefusesUnknownKindsTheCapAndDisabled(): bool
	{
		$sender = $this->makeSender();
		$sender->reportRefusal('password_wrong', 'not a vocabulary kind');
		$unknownIsSilent = $sender->queueCount() === 0;
		
		$sender->securityAllowed = false;
		$sender->reportRefusal('auth_failure', 'over the cap');
		$cappedIsSilent = $sender->queueCount() === 0;
		
		$disabled = $this->makeSender(enabled: false);
		$disabled->reportRefusal('auth_failure', 'sender is off');
		
		return $unknownIsSilent
			&& $cappedIsSilent
			&& $disabled->queueCount() === 0;
	}
	
	/** a call without a message still names its event — by its kind */
	public function reportRefusalWithoutAMessageNamesItsKind(): bool
	{
		$sender = $this->makeSender();
		
		$sender->reportRefusal('csrf_reject');
		$payload = $sender->lastPayload();
		
		return ($payload['message'] ?? '') === 'csrf_reject'
			&& ($payload['events'][0]['className'] ?? null) === 'csrf_reject';
	}
	
	/** the username mask call sites are told to use — first char, no length leak */
	public function maskNameKeepsOnlyTheFirstCharacter(): bool
	{
		return ConsoleSender::maskName('marcin') === 'm***'
			&& ConsoleSender::maskName('Ökonom') === 'Ö***'
			&& ConsoleSender::maskName('') === '';
	}
	
	public function notFoundExceptionBecomesA404WhenEnabled(): bool
	{
		$sender = $this->makeSender(report404: true);
		
		// the flush-time Events merge is where framework 404s arrive; the
		// router's own message is preserved ("File not found: …", a controller
		// miss — the subtype detail worth keeping), not flattened to a fixed text
		$sender->mergeEvent(new NotFoundException('File not found: themes/x/favicon.png'));
		$payload = $sender->lastPayload();
		
		return ($payload['type'] ?? null) === '404'
			&& ($payload['priority'] ?? null) === Priority::INFO
			&& ($payload['message'] ?? '') === 'File not found: themes/x/favicon.png';
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
	 * PHP refusing a request body during startup (a malformed multipart POST
	 * from an upload-exploit scanner) reaches the merge as the ErrorException
	 * handleShutdown() builds from error_get_last(). The application never had
	 * a say — access noise like a router 404, NOT gated by the report_404
	 * opt-in (which exists because a routing miss can be a real broken link).
	 */
	public function requestStartupRefusalIsAccessNoiseEvenWithout404Optin(): bool
	{
		$sender = $this->makeSender(report404: false);
		
		$message = 'PHP Request Startup: Missing boundary in multipart/form-data POST data';
		$sender->mergeEvent(new ErrorException($message,
			0,
			E_WARNING,
			'Unknown',
			0,
		));
		$payload = $sender->lastPayload();
		
		return ($payload['type'] ?? null) === '404'
			&& ($payload['priority'] ?? null) === Priority::INFO
			&& ($payload['message'] ?? '') === $message;
	}
	
	/**
	 * Guard against overbroad matching: only the "PHP Request Startup: "
	 * prefix marks a warning as client-caused — an ordinary runtime warning
	 * keeps its place in the error band.
	 */
	public function ordinaryWarningStaysInTheErrorBand(): bool
	{
		$sender = $this->makeSender(report404: false);
		
		$sender->mergeEvent(new ErrorException('Undefined array key "boundary"',
			0,
			E_WARNING,
			'/app/src/Upload.php',
			42,
		));
		$payload = $sender->lastPayload();
		
		return ($payload['type'] ?? null) === null
			&& ($payload['priority'] ?? null) === Priority::WARNING;
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
			/**
			 * The rolling APCu cap, scripted: the real allowSecurity() reads a
			 * shared per-minute counter, so suite runs inside one minute would
			 * interfere with each other's counts
			 */
			public bool $securityAllowed = true;
			
			protected function allowSecurity(): bool
			{
				return $this->securityAllowed;
			}
			
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
