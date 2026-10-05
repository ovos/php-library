<?php
declare(strict_types=1);

namespace Tests\Service\Codesafe;

use Ovos\ArrayObject;
use Ovos\Service\Codesafe\Sender as CodesafeSender;
use Ovos\Service\Codesafe\Spool as CodesafeSpool;
use Ovos\Test;
use Ovos\Test\Internal;

use function apcu_enabled;
use function apcu_exists;
use function apcu_fetch;
use function array_shift;
use function dirname;
use function file_get_contents;
use function function_exists;
use function is_int;
use function str_contains;
use function str_repeat;

/**
 * The batches codesafe did not take wait for a later request (codesafe's
 * failure-mode audit, the sender's low): Sender::send() posted each batch
 * once and never read the answer, so a codesafe deploy, an outage or a 503
 * lost every error of those seconds. Only a batch codesafe certainly did not
 * take is kept (retryable()), in APCu under a fixture prefix here, and the
 * posts back off while codesafe says later. The transport is scripted
 * through transmit() — nothing is posted.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Spool extends Test
{
	protected const string PREFIX = 'zz-spool-test';
	
	#[Internal]
	public function isDisabled(): bool
	{
		if(function_exists('apcu_enabled') === false || apcu_enabled() === false)
		{
			$this->reason = 'APCu unavailable';
			
			return true;
		}
		
		return false;
	}
	
	#[Internal]
	public function prepare(): void
	{
		(new CodesafeSpool(self::PREFIX))->clear();
	}
	
	#[Internal]
	public function deconstruct(): void
	{
		(new CodesafeSpool(self::PREFIX))->clear();
	}
	
	/**
	 * RULE: kept is only what codesafe certainly did not take — nothing left
	 * the box, or 429/502/503/504; a 2xx is done, a 4xx final, and an answer
	 * lost after sending (a read timeout) may have been stored
	 */
	public function onlyWhatWasNotTakenIsKept(): bool
	{
		return CodesafeSender::retryable(0, false) === true
			&& CodesafeSender::retryable(503, true) === true
			&& CodesafeSender::retryable(429, true) === true
			&& CodesafeSender::retryable(502, true) === true
			&& CodesafeSender::retryable(504, true) === true
			&& CodesafeSender::retryable(202, true) === false
			&& CodesafeSender::retryable(400, true) === false
			&& CodesafeSender::retryable(401, true) === false
			&& CodesafeSender::retryable(413, true) === false
			&& CodesafeSender::retryable(500, true) === false
			&& CodesafeSender::retryable(0, true) === false;
	}
	
	/**
	 * RULE: the transport says codesafe may have the batch when an answer
	 * came or the whole body went out — libcurl's upload count is 0 when the
	 * name, the connection or TLS failed (checked against a closed port, an
	 * unresolvable name and a 401); read off the source, it posts
	 */
	public function theTransportSaysWhetherCodesafeMayHaveIt(): bool
	{
		$source = (string)file_get_contents(dirname(__DIR__, 3) . '/src/Service/Codesafe/Sender.php');
		
		return str_contains($source, "\$status = (int)curl_getinfo(\$handle, CURLINFO_RESPONSE_CODE);")
			&& str_contains($source, "\$status > 0 || (int)curl_getinfo(\$handle, CURLINFO_SIZE_UPLOAD_T) >= strlen(\$json),");
	}
	
	/** RULE: first kept, first taken; empty answers null; the size is the tail past the head */
	public function theSpoolIsFirstInFirstOut(): bool
	{
		$spool = new CodesafeSpool(self::PREFIX);
		$spool->clear();
		$spool->keep('a');
		$spool->keep('b');
		$size = $spool->size();
		$first = $spool->take();
		$spool->keep('c');
		
		$order = [$spool->take(), $spool->take(), $spool->take(), $spool->size()];
		// an empty take moves nothing: the next batch kept is the next taken
		$spool->keep('d');
		
		return $size === 2
			&& $first === 'a'
			&& $order === ['b', 'c', null, 0]
			&& $spool->take() === 'd';
	}
	
	/** RULE: past MAX_BATCHES the oldest go — a long outage keeps the newest */
	public function aLongOutageKeepsTheNewest(): bool
	{
		$spool = new CodesafeSpool(self::PREFIX);
		$spool->clear();
		for($i = 1; $i <= CodesafeSpool::MAX_BATCHES + 3; $i++)
		{
			$spool->keep('batch ' . $i);
		}
		
		return $spool->size() === CodesafeSpool::MAX_BATCHES
			&& $spool->take() === 'batch 4';
	}
	
	/**
	 * RULE: the spool never crowds the application's cache out while
	 * codesafe is unreachable (MG 2026-10-05): the batches passed over are
	 * deleted at once — only MAX_BATCHES slots exist, not an outage's worth
	 * waiting for their TTL — a batch over MAX_BATCH_BYTES is never kept, and
	 * nothing is kept while APCu has less than MIN_FREE_SHARE free
	 */
	public function theSpoolNeverCrowdsTheCache(): bool
	{
		$spool = new class(self::PREFIX) extends CodesafeSpool
		{
			public bool $room = true;
			
			/** the slots that exist in APCu, passed over or not */
			public function slots(): int
			{
				$count = 0;
				$tail = apcu_fetch($this->key('tail'));
				for($slot = 1; is_int($tail) && $slot <= $tail; $slot++)
				{
					$count+= apcu_exists($this->key('slot:' . $slot)) ? 1 : 0;
				}
				
				return $count;
			}
			
			protected function roomy(): bool
			{
				return $this->room;
			}
		};
		$spool->clear();
		for($i = 1; $i <= 3 * CodesafeSpool::MAX_BATCHES; $i++)
		{
			$spool->keep('batch ' . $i);
		}
		$bounded = [$spool->slots(), $spool->size()];
		$big = $spool->keep(str_repeat('x', CodesafeSpool::MAX_BATCH_BYTES + 1));
		$spool->room = false;
		$crowded = $spool->keep('small');
		
		return $bounded === [CodesafeSpool::MAX_BATCHES, CodesafeSpool::MAX_BATCHES]
			&& $big === false
			&& $crowded === false
			&& $spool->size() === CodesafeSpool::MAX_BATCHES;
	}
	
	/**
	 * RULE: a retryable answer keeps the batch and backs off; while backing
	 * off a batch is kept without a post; a 2xx takes one kept batch along
	 * (re-kept when it fails again); a final answer drops the batch
	 */
	public function aBatchCodesafeDidNotTakeIsPostedLater(): bool
	{
		$spool = new CodesafeSpool(self::PREFIX);
		$spool->clear();
		
		// codesafe answers 503: kept, and the posts back off
		$sender = $this->sender([[503, true]]);
		$sender->sendOne('one');
		$afterRefusal = [$sender->posted, $spool->size(), $spool->backingOff()];
		
		// backing off: the next batch is kept without a post
		$sender = $this->sender([]);
		$sender->sendOne('two');
		$whileBackingOff = [$sender->posted, $spool->size()];
		
		// codesafe is back: the batch goes, and the oldest kept rides along
		$spool->clear();
		$spool->keep('one');
		$spool->keep('two');
		$sender = $this->sender([[202, true], [202, true]]);
		$sender->sendOne('three');
		$recovered = [$sender->posted, $spool->size()];
		
		// the kept one refused again on the way: kept again, and backing off
		$sender = $this->sender([[202, true], [503, true]]);
		$sender->sendOne('four');
		$refusedAgain = [$sender->posted, $spool->size(), $spool->backingOff(), $spool->take()];
		
		// final answers drop: a 400, and a timeout after the batch went out —
		// and take nothing kept along: only a 2xx says codesafe is taking
		$spool->clear();
		$spool->keep('kept');
		$final = $this->sender([[400, true]]);
		$final->sendOne('five');
		$lost = $this->sender([[0, true]]);
		$lost->sendOne('six');
		$dropped = [$final->posted, $lost->posted, $spool->size(), $spool->backingOff()];
		
		return $afterRefusal === [['one'], 1, true]
			&& $whileBackingOff === [[], 2]
			&& $recovered === [['three', 'one'], 1]
			&& $refusedAgain === [['four', 'two'], 1, true, 'two']
			&& $dropped === [['five'], ['six'], 1, false];
	}
	
	/**
	 * RULE: after fastcgi_finish_request() released the client, a batch
	 * codesafe did not take is posted once more, a moment later — a restart's
	 * blip passes without the spool; refused again it is kept. Before the
	 * release (mod_php, a CLI) there is no retry — the visitor would wait for
	 * it — and a final answer is never retried (MG 2026-10-05)
	 */
	public function aBlipIsRetriedOnceAfterTheResponse(): bool
	{
		$spool = new CodesafeSpool(self::PREFIX);
		
		$spool->clear();
		$blip = $this->sender([[0, false], [202, true]]);
		$blip->finished = true;
		$blip->sendOne('a');
		$passed = [$blip->posted, $blip->paused, $spool->size(), $spool->backingOff()];
		
		$spool->clear();
		$outage = $this->sender([[503, true], [503, true]]);
		$outage->finished = true;
		$outage->sendOne('b');
		$kept = [$outage->posted, $outage->paused, $spool->size(), $spool->backingOff()];
		
		$spool->clear();
		$waiting = $this->sender([[0, false]]);
		$waiting->sendOne('c');
		$unreleased = [$waiting->posted, $waiting->paused, $spool->size()];
		
		$spool->clear();
		$final = $this->sender([[400, true]]);
		$final->finished = true;
		$final->sendOne('d');
		
		// "released" is the call itself, recorded by the application — read off
		// the source: a CLI test run has no FastCGI to call it
		$application = (string)file_get_contents(dirname(__DIR__, 3) . '/src/Application.php');
		$sender = (string)file_get_contents(dirname(__DIR__, 3) . '/src/Service/Codesafe/Sender.php');
		
		return $passed === [['a', 'a'], 1, 0, false]
			&& $kept === [['b', 'b'], 1, 1, true]
			&& $unreleased === [['c'], 0, 1]
			&& $final->posted === ['d'] && $final->paused === 0
			&& str_contains($application, '$this->responseFinished = fastcgi_finish_request();')
			&& str_contains($sender, 'return isset($this->app) && $this->app->isResponseFinished();');
	}
	
	/**
	 * A sender whose transport answers $answers in turn ([status, sent]) and
	 * records every batch it was handed, its spool under the fixture prefix
	 *
	 * @param list<array{int, bool}> $answers
	 */
	protected function sender(
		array $answers,
	): CodesafeSender
	{
		return new class($answers) extends CodesafeSender
		{
			public array $posted = [];
			
			/** whether fastcgi_finish_request() has released the client */
			public bool $finished = false;
			
			/** how many times it waited before a retry */
			public int $paused = 0;
			
			public function __construct(
				protected array $answers,
			)
			{
				parent::__construct(new ArrayObject(['enabled' => true, 'url' => 'https://codesafe.invalid', 'key' => 'test-key']));
			}
			
			public function sendOne(
				string $json,
			): void
			{
				$this->send($json);
			}
			
			protected function spool(): ?CodesafeSpool
			{
				return new CodesafeSpool('zz-spool-test');
			}
			
			protected function transmit(
				string $json,
			): array
			{
				$this->posted[] = $json;
				
				return array_shift($this->answers) ?? [0, false];
			}
			
			protected function released(): bool
			{
				return $this->finished;
			}
			
			protected function pause(): void
			{
				$this->paused++;
			}
		};
	}
}
