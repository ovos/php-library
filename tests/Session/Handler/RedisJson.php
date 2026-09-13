<?php
declare(strict_types=1);

namespace Tests\Session\Handler;

use Ovos\ArrayObject;
use Ovos\Connection\Redis as Connection;
use Ovos\Container\Inject;
use Ovos\Session\Handler\RedisJson as Handler;
use Ovos\Session\Node;
use Ovos\Test;
use Ovos\Test\Internal;
use Ovos\Test\Parallel;
use Ovos\Url;
use DateTime;
use Override;
use stdClass;
use RedisException;
use RuntimeException;
use Throwable;

use function bin2hex;
use function count;
use function is_int;
use function microtime;
use function random_bytes;
use function sprintf;
use function time;
use function usleep;

use const DIRECTORY_SEPARATOR;

/**
 * RedisJson
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class RedisJson extends Test
{
	public const string PREFIX = 'tests:sessions';
	
	public const int CLIENTS = 3;
	
	#[Inject('config')]
	protected ArrayObject $config;
	
	protected ?Connection $connection = null;
	
	protected ?Connection $queueConnection = null;
	
	/**
	 * @var Handler[]
	 */
	protected array $sessions = [];
	
	public function __construct()
	{
		$connectionConfig = $this->config
			->getPath(['connections', 'redis']);
		if($connectionConfig === null)
		{
			$this->setDisabled(true,
				'"connections.redis" config is missing.');
			
			return;
		}
		
		$connection = new Connection($connectionConfig);
		if($connection->connect() === false)
		{
			$this->setDisabled(true,
				'redis is not available.');
			
			return;
		}
		
		// the handler requires the RedisJSON module
		try
		{
			$client = $connection->getClient();
			$probe = $client->rawCommand('JSON.SET',
				self::PREFIX . ':probe', '$', '{}');
			if($probe === false)
			{
				$this->setDisabled(true,
					'RedisJSON module is not available.');
				
				return;
			}
			$client->del(self::PREFIX . ':probe');
		}
		catch(RedisException)
		{
			$this->setDisabled(true,
				'RedisJSON module is not available.');
			
			return;
		}
		
		$this->connection = $connection;
		$this->queueConnection = new Connection($connectionConfig);
	}
	
	protected function handlerConfig(): ArrayObject
	{
		// the lock ttl must match tests/Session/Handler/RedisJson/Client.file.php
		return new ArrayObject([
			'lifetime' => 60,
			'lock' => [
				'enabled' => true,
				'lock_ttl_ms' => 3000,
				'wait_attempts' => 3,
			],
			'journey' => [
				'limit' => 5,
			],
		]);
	}
	
	/**
	 * A fresh session bound to a random id, cleaned up by finalize()
	 */
	protected function session(
		?string $prefix = null,
	): Handler
	{
		$session = new Handler(
			$this->connection,
			$this->queueConnection,
			$prefix ?? self::PREFIX,
			$this->handlerConfig(),
		);
		$session->open(bin2hex(random_bytes(16)));
		
		$this->sessions[] = $session;
		
		return $session;
	}
	
	public function roundTrip(): bool
	{
		$session = $this->session();
		$session->set(['basket', 'products'], ['a' => 1, 'b' => 2]);
		
		return $session->get(['basket', 'products']) === ['a' => 1, 'b' => 2];
	}
	
	public function typesSurviveTheRoundTrip(): bool
	{
		$values = [
			'int' => 42,
			'float' => 1.5,
			'bool' => true,
			'string' => 'täxt',
			'list' => [1, 2, 3],
			'map' => ['x' => 'y'],
		];
		
		$session = $this->session();
		$session->set(['types'], $values);
		
		return $session->get(['types']) === $values;
	}
	
	public function nestedSetCreatesParents(): bool
	{
		$session = $this->session();
		$session->set(['a', 'b', 'c'], 1);
		
		return $session->get(['a']) === ['b' => ['c' => 1]];
	}
	
	public function scalarParentIsOverwritten(): bool
	{
		$session = $this->session();
		$session->set(['a'], 5);
		$session->set(['a', 'b'], 1);
		
		return $session->get(['a', 'b']) === 1;
	}
	
	public function specialCharactersInKeys(): bool
	{
		$session = $this->session();
		$session->set(['we"ird', 'do.t\\ted'], 'x');
		
		return $session->get(['we"ird', 'do.t\\ted']) === 'x'
			&& $session->has(['we"ird', 'do.t\\ted']) === true;
	}
	
	/**
	 * INTEGER segments address json array elements; numeric STRINGS
	 * stay object keys - only the array path form carries real indices
	 */
	public function integerSegmentsAddressArrayElements(): bool
	{
		$session = $this->session();
		$session->set(['items'], ['a', 'b', 'c']);
		
		$read = $session->get(['items', 1]);
		$session->set(['items', 1], 'B');
		$session->increment(['nums', 'list']); // creates nums.list = 1
		
		$stringKey = $this->session();
		$stringKey->set(['map', '0'], 'zero'); // an object key, not an index
		
		return $read === 'b'
			&& $session->get(['items', 1]) === 'B'
			&& $session->get(['items']) === ['a', 'B', 'c']
			&& $session->node(['items'])[2] === 'c'
			&& $stringKey->get(['map']) === ['0' => 'zero'];
	}
	
	public function missingValueIsNull(): bool
	{
		$session = $this->session();
		
		return $session->get(['nothing', 'here']) === null
			&& $session->has(['nothing']) === false;
	}
	
	public function hasAndRemove(): bool
	{
		$session = $this->session();
		$session->set(['a', 'b'], null);
		
		$had = $session->has(['a', 'b']); // null value still exists
		$session->remove(['a', 'b']);
		
		return $had === true
			&& $session->has(['a', 'b']) === false
			&& $session->has(['a']) === true;
	}
	
	public function documentIsCreatedLazily(): bool
	{
		$session = $this->session();
		
		$before = $session->exists();
		$session->get(['anything']); // reads do not create the document
		$beforeWrite = $session->exists();
		$session->set(['anything'], 1);
		
		return $before === false
			&& $beforeWrite === false
			&& $session->exists() === true;
	}
	
	public function metaCreatedIsStamped(): bool
	{
		$session = $this->session();
		$session->set(['a'], 1);
		
		$all = $session->all();
		
		return ($all['a'] ?? null) === 1
			&& is_int($all[Handler::KEY_META]['created'] ?? null);
	}
	
	public function rootWriteReplacesTheDocument(): bool
	{
		$session = $this->session();
		$session->set(['a'], 1);
		$session->set([], ['only' => 1]);
		
		return $session->all() === ['only' => 1]
			&& $session->get(['only']) === 1;
	}
	
	public function increments(): bool
	{
		$session = $this->session();
		
		return $session->increment(['counter']) === 1
			&& $session->increment(['counter'], 2) === 3
			&& $session->increment(['stats', 'ratio'], 0.5) == 0.5
			&& $session->get(['counter']) === 3;
	}
	
	public function expirationIsApplied(): bool
	{
		$session = $this->session();
		$session->set(['a'], 1);
		
		$ttl = $this->connection->getClient()
			->pttl(self::PREFIX . ':' . $session->getSessionId());
		
		return $ttl > 0 && $ttl <= 60000;
	}
	
	public function renameMovesTheDocument(): bool
	{
		$session = $this->session();
		$session->set(['a'], 1);
		
		$oldSessionId = $session->getSessionId();
		$newSessionId = bin2hex(random_bytes(16));
		
		$renamed = $session->rename($newSessionId);
		
		return $renamed === true
			&& $session->getSessionId() === $newSessionId
			&& (int)$this->connection->getClient()
				->exists(self::PREFIX . ':' . $oldSessionId) === 0
			&& $session->get(['a']) === 1;
	}
	
	public function renameCanKeepTheOldDocument(): bool
	{
		$session = $this->session();
		$session->set(['a'], 1);
		
		$oldSessionId = $session->getSessionId();
		$renamed = $session->rename(bin2hex(random_bytes(16)), false);
		
		$oldExists = (int)$this->connection->getClient()
			->exists(self::PREFIX . ':' . $oldSessionId) === 1;
		$this->connection->getClient()
			->del(self::PREFIX . ':' . $oldSessionId);
		
		return $renamed === true
			&& $oldExists === true
			&& $session->get(['a']) === 1;
	}
	
	public function destroyDeletesTheDocument(): bool
	{
		$session = $this->session();
		$session->set(['a'], 1);
		$session->destroy();
		
		return $session->exists() === false;
	}
	
	/**
	 * stdClass is plain json data (Model::export() produces it) - it is
	 * stored per-path addressable and comes back as an array, exactly
	 * what Model::restore() takes
	 */
	public function stdClassBecomesAddressableJson(): bool
	{
		$export = new stdClass;
		$export->id = 18;
		$export->username = 'neo';
		$export->nested = new stdClass;
		$export->nested->deep = true;
		
		$session = $this->session();
		$session->set(['auth', 'user'], $export);
		
		return $session->get(['auth', 'user', 'username']) === 'neo'
			&& $session->get(['auth', 'user', 'nested']) === ['deep' => true]
			&& $session->get(['auth', 'user']) === [
				'id' => 18,
				'username' => 'neo',
				'nested' => ['deep' => true],
			];
	}
	
	public function objectsSurviveAsSerializedLeaves(): bool
	{
		$date = new DateTime('2026-07-06 12:00:00');
		
		$session = $this->session();
		$session->set(['auth', 'return'], $date);
		
		$read = $session->get(['auth', 'return']);
		
		return $read instanceof DateTime
			&& $read == $date;
	}
	
	/**
	 * A plain user-space class (here Ovos\Url, exactly what a production application stores as
	 * the post-login return_url) is not JsonSerializable/stdClass/ArrayObject,
	 * so it round-trips through the serialized leaf and comes back as a real
	 * instance - unlike DateTime it has no __serialize magic, so this covers
	 * the generic "every property is serialized" path
	 */
	public function userSpaceObjectsSurviveAsSerializedLeaves(): bool
	{
		$url = new Url('account', 'orders');
		
		$session = $this->session();
		$session->set(['auth', 'return_url'], $url);
		
		$read = $session->get(['auth', 'return_url']);
		
		return $read instanceof Url
			&& $read == $url;
	}
	
	public function arrayObjectsBecomeAddressableJson(): bool
	{
		$session = $this->session();
		$session->set(['ns'], new ArrayObject([
			'a' => 1,
			'b' => ['c' => 2],
		]));
		
		// the whole point: nested path access INTO the stored object
		return $session->get(['ns', 'b', 'c']) === 2
			&& $session->get(['ns']) === ['a' => 1, 'b' => ['c' => 2]];
	}
	
	/**
	 * An ArrayObject is deep-normalized even though it is now
	 * JsonSerializable: a nested opaque object still round-trips as a
	 * serialized leaf and a nested binary string survives - the reason the
	 * ArrayObject branch is checked before the JsonSerializable passthrough
	 */
	public function arrayObjectIsDeepNormalized(): bool
	{
		$session = $this->session();
		$session->set(['ns'], new ArrayObject([
			'when' => new DateTime('2026-07-06 12:00:00'),
			'token' => "\x00\x01\xff\xfe",
		]));
		
		$when = $session->get(['ns', 'when']);
		
		return $when instanceof DateTime
			&& $when == new DateTime('2026-07-06 12:00:00')
			&& $session->get(['ns', 'token']) === "\x00\x01\xff\xfe";
	}
	
	public function lockedValueIsHeldAndReleasedBySet(): bool
	{
		$session = $this->session();
		$session->set(['v'], 'old');
		
		$value = $session->getLocked(['v']);
		
		$lockKey = self::PREFIX . ':'
			. $session->getSessionId() . ':v:lock';
		$client = $this->connection->getClient();
		
		$lockedExists = (int)$client->exists($lockKey) === 1;
		$ownRead = $session->get(['v']); // own locks never block
		
		$session->set(['v'], 'new');
		$releasedBySet = (int)$client->exists($lockKey) === 0;
		
		return $value === 'old'
			&& $lockedExists === true
			&& $ownRead === 'old'
			&& $releasedBySet === true
			&& $session->get(['v']) === 'new';
	}
	
	public function lockCanBeReleasedWithoutWriting(): bool
	{
		$session = $this->session();
		$session->set(['v'], 'old');
		$session->getLocked(['v']);
		
		$released = $session->releaseLock(['v']);
		
		$lockKey = self::PREFIX . ':'
			. $session->getSessionId() . ':v:lock';
		
		return $released === true
			&& (int)$this->connection->getClient()
				->exists($lockKey) === 0
			&& $session->get(['v']) === 'old';
	}
	
	public function journeyTimeline(): bool
	{
		$session = $this->session();
		$session->addAction('ticket bought', ['ticket' => 42]);
		$session->addRequest('GET', '/tickets');
		
		$journey = $session->getJourney();
		
		return count($journey) === 2
			&& $journey[0]['type'] === Handler::JOURNEY_ACTION
			&& $journey[0]['action'] === 'ticket bought'
			&& $journey[0]['data']['ticket'] === 42
			&& is_int($journey[0]['t'])
			&& $journey[1]['type'] === Handler::JOURNEY_REQUEST
			&& $journey[1]['method'] === 'GET'
			&& $journey[1]['url'] === '/tickets';
	}
	
	public function journeyIsTrimmedToTheLimit(): bool
	{
		$session = $this->session();
		for($action = 0; $action < 7; $action++)
		{
			$session->addAction('a' . $action);
		}
		
		$journey = $session->getJourney();
		
		// config limit is 5 - the two oldest entries were dropped
		return count($journey) === 5
			&& $journey[0]['action'] === 'a2'
			&& $journey[4]['action'] === 'a6';
	}
	
	public function activeSessionsAreCounted(): bool
	{
		// an isolated (but fixed - each prefix loads its own function
		// library) prefix keeps the activity set deterministic
		$prefix = self::PREFIX . ':count';
		
		try
		{
			$first = $this->session($prefix);
			$first->set(['a'], 1); // touches
			$one = $first->countActive() === 1;
			
			$second = $this->session($prefix);
			$second->get(['a']); // reads touch too
			$two = $second->countActive() === 2;
			
			return $one && $two;
		}
		finally
		{
			$client = $this->connection->getClient();
			if($keys = $client->keys($prefix . '*'))
			{
				$client->del($keys);
			}
		}
	}
	
	public function peekMaterializesScalarsOnly(): bool
	{
		$session = $this->session();
		$session->set(['flag'], true);
		$session->set(['count'], 42);
		$session->set(['nothing'], null);
		$session->set(['ns'], ['a' => 1]);
		$session->set(['when'], new DateTime('2026-07-06'));
		
		return $session->peek(['flag']) === true
			&& $session->peek(['count']) === 42
			&& $session->peek(['nothing']) === null
			&& $session->peek(['ns']) instanceof Node
			&& $session->peek(['missing']) instanceof Node
			&& $session->peek(['when']) instanceof Node; // serialized leaf stays lazy
	}
	
	public function appendsToLists(): bool
	{
		$session = $this->session();
		
		$first = $session->append(['basket', 'products'], 'a');
		$second = $session->append(['basket', 'products'], 'b');
		
		// a scalar in the way is replaced by a fresh list
		$session->set(['x'], 5);
		$overwritten = $session->append(['x'], 'a');
		
		return $first === 1
			&& $second === 2
			&& $session->get(['basket', 'products']) === ['a', 'b']
			&& $overwritten === 1
			&& $session->get(['x']) === ['a'];
	}
	
	public function appendTrimsToTheLimit(): bool
	{
		$session = $this->session();
		
		$length = null;
		for($entry = 0; $entry < 5; $entry++)
		{
			$length = $session->append(['log'], 'e' . $entry, 3);
		}
		
		return $length === 3
			&& $session->get(['log']) === ['e2', 'e3', 'e4'];
	}
	
	public function updateAppliesAndReleases(): bool
	{
		$session = $this->session();
		$session->set(['counter'], 1);
		
		$result = $session->update(['counter'],
			fn(mixed $value): mixed => $value + 1);
		
		$lockKey = self::PREFIX . ':'
			. $session->getSessionId() . ':counter:lock';
		
		return $result === 2
			&& $session->get(['counter']) === 2
			&& (int)$this->connection->getClient()->exists($lockKey) === 0;
	}
	
	public function updateReleasesTheLockOnFailure(): bool
	{
		$session = $this->session();
		$session->set(['v'], 'kept');
		
		try
		{
			$session->update(['v'], function(): void
			{
				throw new RuntimeException('updater failed');
			});
			
			return false;
		}
		catch(RuntimeException)
		{
		}
		
		$lockKey = self::PREFIX . ':'
			. $session->getSessionId() . ':v:lock';
		
		return (int)$this->connection->getClient()->exists($lockKey) === 0
			&& $session->get(['v']) === 'kept';
	}
	
	public function getManyReadsSeveralPathsAtOnce(): bool
	{
		$session = $this->session();
		$session->set(['a', 'b'], 1);
		$session->set(['c'], 'two');
		
		$values = $session->getMany([['a', 'b'], ['c'], ['missing']]);
		
		return $values === [
			'a.b' => 1,
			'c' => 'two',
			'missing' => null,
		];
	}
	
	public function ancestorLockCoversDescendants(): bool
	{
		$session = $this->session();
		$session->set(['basket', 'products'], ['a']);
		
		// a lock on the PARENT held by another request, expiring shortly
		$lockKey = self::PREFIX . ':'
			. $session->getSessionId() . ':basket:lock';
		$this->connection->getClient()
			->set($lockKey, 'foreign', ['PX' => 400]);
		
		$start = microtime(true);
		$value = $session->get(['basket', 'products']);
		$elapsed = microtime(true) - $start;
		
		// the child read waited for the foreign parent lock to go away
		return $value === ['a']
			&& $elapsed >= 0.3;
	}
	
	public function ownAncestorLockDoesNotBlock(): bool
	{
		$session = $this->session();
		$session->set(['basket', 'products'], ['a']);
		$session->getLocked(['basket']);
		
		$start = microtime(true);
		$value = $session->get(['basket', 'products']);
		$elapsed = microtime(true) - $start;
		
		$session->set(['basket'], ['products' => ['a', 'b']]);
		
		return $value === ['a']
			&& $elapsed < 0.2
			&& $session->get(['basket', 'products']) === ['a', 'b'];
	}
	
	public function binaryStringsSurvive(): bool
	{
		$binary = "\x00\x01\xff\xfe";
		
		$session = $this->session();
		$session->set(['token'], $binary);
		
		return $session->get(['token']) === $binary;
	}
	
	public function garbageCollectsStaleActivity(): bool
	{
		// an isolated (fixed, see activeSessionsAreCounted) prefix
		$prefix = self::PREFIX . ':gc';
		
		try
		{
			$session = $this->session($prefix);
			$session->set(['a'], 1); // touches: a fresh activity member
			
			// a member whose session expired long ago (lifetime is 60)
			$this->connection->getClient()->zAdd($prefix . ':activity',
				time() - 3600, 'stalestalestale');
			
			return $session->gc() === 1
				&& $session->countActive() === 1;
		}
		finally
		{
			$client = $this->connection->getClient();
			if($keys = $client->keys($prefix . '*'))
			{
				$client->del($keys);
			}
		}
	}
	
	/**
	 * A writer process locks the value and writes it after a pause; a
	 * plain get() in this process must wait for that write and return
	 * the NEW value instead of the stale one
	 */
	public function parallelReaderWaitsForTheLockedValue(): bool
	{
		$session = $this->session();
		$session->set(['v'], 'old');
		
		$process = Parallel::spawn($this->clientCommand('writer', $session));
		if($process === null)
		{
			return false;
		}
		
		try
		{
			// wait until the writer process actually holds the value lock
			$flagKey = self::PREFIX . ':flag:' . $session->getSessionId();
			$client = $this->connection->getClient();
			
			$locked = false;
			for($attempt = 0; $attempt < 200; $attempt++)
			{
				if((int)$client->exists($flagKey) === 1)
				{
					$locked = true;
					
					break;
				}
				usleep(50000);
			}
			if($locked === false)
			{
				return false;
			}
			
			$start = microtime(true);
			$value = $session->get(['v']);
			$elapsed = microtime(true) - $start;
			
			// the writer sleeps 700ms while holding the lock: a correct
			// reader waited for the release and saw the new value
			return $value === 'new'
				&& $elapsed >= 0.2;
		}
		finally
		{
			Parallel::close($process);
			$this->connection->getClient()
				->del(self::PREFIX . ':flag:' . $session->getSessionId());
		}
	}
	
	/**
	 * A blind write must not sail past a value lock held by another
	 * process: the Lua side refuses atomically, the writer waits for
	 * the release and retries - the write lands AFTER the holder's own
	 */
	public function parallelWriterWaitsForTheLockedValue(): bool
	{
		$session = $this->session();
		$session->set(['v'], 'old');
		
		$process = Parallel::spawn($this->clientCommand('writer', $session));
		if($process === null)
		{
			return false;
		}
		
		try
		{
			// wait until the writer process actually holds the value lock
			$flagKey = self::PREFIX . ':flag:' . $session->getSessionId();
			$client = $this->connection->getClient();
			
			$locked = false;
			for($attempt = 0; $attempt < 200; $attempt++)
			{
				if((int)$client->exists($flagKey) === 1)
				{
					$locked = true;
					
					break;
				}
				usleep(50000);
			}
			if($locked === false)
			{
				return false;
			}
			
			$start = microtime(true);
			$session->set(['v'], 'mine');
			$elapsed = microtime(true) - $start;
			
			// the holder keeps the lock for 700ms and writes "new" on
			// release: a guarded write waited and won as the LAST writer
			return $session->get(['v']) === 'mine'
				&& $elapsed >= 0.2;
		}
		finally
		{
			Parallel::close($process);
			$this->connection->getClient()
				->del(self::PREFIX . ':flag:' . $session->getSessionId());
		}
	}
	
	/**
	 * Parallel increments from multiple processes must never lose an
	 * update - the increment is a single atomic server-side function
	 */
	public function parallelIncrementsAreAtomic(): bool
	{
		$session = $this->session();
		
		Parallel::run(
			$this->clientCommand('increment', $session),
			self::CLIENTS,
		);
		
		// each client increments 20 times
		return $session->get(['counter']) === self::CLIENTS * 20;
	}
	
	protected function clientCommand(
		string $mode,
		Handler $session,
	): string
	{
		$phpBinary = $this->config->getPath(['cli', 'executable']) ?? 'php';
		
		return sprintf('%s %s %s %s', $phpBinary,
			__DIR__
			. DIRECTORY_SEPARATOR . 'RedisJson'
			. DIRECTORY_SEPARATOR . 'Client.file.php',
			$mode,
			$session->getSessionId(),
		);
	}
	
	/**
	 * Called by the runner after each test method
	 */
	#[Internal]
	#[Override]
	public function finalize(): void
	{
		foreach($this->sessions as $session)
		{
			try
			{
				$session->destroy();
			}
			catch(Throwable)
			{
				// cleanup only
			}
		}
		$this->sessions = [];
	}
	
	/**
	 * Called by the runner after all test methods have been invoked
	 */
	#[Internal]
	#[Override]
	public function deconstruct(): void
	{
		if($this->connection === null)
		{
			return;
		}
		
		$client = $this->connection->getClient();
		if($keys = $client->keys(self::PREFIX . ':*'))
		{
			$client->del($keys);
		}
	}
}
