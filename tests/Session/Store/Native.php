<?php
declare(strict_types=1);

namespace Tests\Session\Store;

use Ovos\ArrayObject;
use Ovos\Session\Store\Native as NativeStore;
use Ovos\Test;
use RuntimeException;

use function array_key_exists;
use function count;

/**
 * Native
 *
 * The $_SESSION / local-array store - no external services, so every test
 * builds a fresh store in memory (unbound, or bound to a local array by
 * reference to exercise the $_SESSION write-through).
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Native extends Test
{
	protected function store(): NativeStore
	{
		return new NativeStore;
	}
	
	public function roundTrip(): bool
	{
		$store = $this->store();
		$store->set(['greeting'], 'hello');
		
		return $store->get(['greeting']) === 'hello';
	}
	
	/**
	 * Missing (or scalar) parents become ArrayObject namespaces - the
	 * framework's convention; the leaf is still read back per path
	 */
	public function nestedSetCreatesNamespaces(): bool
	{
		$store = $this->store();
		$store->set(['a', 'b', 'c'], 1);
		
		return $store->get(['a', 'b', 'c']) === 1
			&& $store->has(['a', 'b', 'c']) === true
			&& $store->has(['a', 'b']) === true;
	}
	
	public function scalarParentIsOverwritten(): bool
	{
		$store = $this->store();
		$store->set(['a'], 5);
		$store->set(['a', 'b'], 1);
		
		return $store->get(['a', 'b']) === 1;
	}
	
	/**
	 * An existing plain-array parent is wrapped into an ArrayObject, its
	 * data preserved
	 */
	public function existingPlainArrayParentIsWrapped(): bool
	{
		$store = $this->store();
		$store->set([], ['a' => ['b' => 1]]);
		$store->set(['a', 'c'], 2);
		
		return $store->get(['a', 'b']) === 1
			&& $store->get(['a', 'c']) === 2;
	}
	
	public function rootWriteReplacesTheSession(): bool
	{
		$store = $this->store();
		$store->set(['a'], 1);
		$store->set(['keep'], 2);
		$store->set([], ['only' => 1]);
		
		return $store->get(['only']) === 1
			&& $store->has(['a']) === false
			&& $store->has(['keep']) === false;
	}
	
	public function rootScalarIsCastToArray(): bool
	{
		$store = $this->store();
		$store->set([], 'scalar');
		
		return $store->get([0]) === 'scalar';
	}
	
	/**
	 * A missing branch and a walk INTO a scalar both read as null
	 */
	public function missingValueIsNull(): bool
	{
		$store = $this->store();
		$store->set(['scalar'], 5);
		
		return $store->get(['nothing', 'here']) === null
			&& $store->get(['scalar', 'deeper']) === null
			&& $store->has(['nothing']) === false;
	}
	
	public function hasAndRemove(): bool
	{
		$store = $this->store();
		$store->set(['a', 'b'], null);
		
		$had = $store->has(['a', 'b']); // a null value still exists
		$store->remove(['a', 'b']);
		
		return $had === true
			&& $store->has(['a', 'b']) === false
			&& $store->has(['a']) === true;
	}
	
	/**
	 * A plain-array container is a copy on the walk - remove() reattaches
	 * it through the parent
	 */
	public function removeFromPlainArrayReattaches(): bool
	{
		$store = $this->store();
		// a root write keeps plain arrays (no namespace wrapping)
		$store->set([], ['a' => ['x' => 1, 'y' => 2]]);
		$store->remove(['a', 'x']);
		
		return $store->has(['a', 'x']) === false
			&& $store->get(['a', 'y']) === 2;
	}
	
	/**
	 * A non-numeric value resets to 0 instead of raising a TypeError on
	 * the addition (json-handler parity)
	 */
	public function increments(): bool
	{
		$store = $this->store();
		
		$first = $store->increment(['counter']);
		$second = $store->increment(['counter'], 2);
		$ratio = $store->increment(['stats', 'ratio'], 0.5);
		
		$store->set(['label'], 'text');
		$reset = $store->increment(['label']);
		
		return $first === 1
			&& $second === 3
			&& $ratio === 0.5
			&& $reset === 1
			&& $store->get(['counter']) === 3;
	}
	
	public function appendsToLists(): bool
	{
		$store = $this->store();
		
		$first = $store->append(['log'], 'a');
		$second = $store->append(['log'], 'b');
		
		// a scalar in the way is replaced by a fresh list
		$store->set(['x'], 5);
		$overwritten = $store->append(['x'], 'a');
		
		return $first === 1
			&& $second === 2
			&& $store->get(['log']) === ['a', 'b']
			&& $overwritten === 1
			&& $store->get(['x']) === ['a'];
	}
	
	public function appendTrimsToTheLimit(): bool
	{
		$store = $this->store();
		
		$length = null;
		for($entry = 0; $entry < 5; $entry++)
		{
			$length = $store->append(['log'], 'e' . $entry, 3);
		}
		
		return $length === 3
			&& $store->get(['log']) === ['e2', 'e3', 'e4'];
	}
	
	/**
	 * An ArrayObject list is copied out before the append
	 */
	public function appendReadsAnArrayObjectList(): bool
	{
		$backing = ['list' => new ArrayObject([1, 2])];
		$store = new NativeStore($backing);
		
		$length = $store->append(['list'], 3);
		
		return $length === 3
			&& $store->get(['list']) === [1, 2, 3];
	}
	
	public function journeyTimeline(): bool
	{
		$store = $this->store();
		$store->addAction('ticket bought', ['ticket' => 42]);
		$store->addRequest('GET', '/tickets');
		
		$journey = $store->getJourney();
		
		return count($journey) === 2
			&& $journey[0]['type'] === NativeStore::JOURNEY_ACTION
			&& $journey[0]['action'] === 'ticket bought'
			&& $journey[0]['data']['ticket'] === 42
			&& $journey[1]['type'] === NativeStore::JOURNEY_REQUEST
			&& $journey[1]['method'] === 'GET'
			&& $journey[1]['url'] === '/tickets';
	}
	
	public function journeyReadsAnArrayObject(): bool
	{
		$backing = [NativeStore::KEY_JOURNEY => new ArrayObject([
			['type' => NativeStore::JOURNEY_ACTION, 'action' => 'seeded'],
		])];
		$store = new NativeStore($backing);
		
		$journey = $store->getJourney();
		
		return count($journey) === 1
			&& $journey[0]['action'] === 'seeded';
	}
	
	public function getManyReadsSeveralPaths(): bool
	{
		$store = $this->store();
		$store->set(['a', 'b'], 1);
		$store->set(['c'], 'two');
		
		return $store->getMany([['a', 'b'], ['c'], ['missing']]) === [
			'a.b' => 1,
			'c' => 'two',
			'missing' => null,
		];
	}
	
	/**
	 * The native store has no value locks: getLocked() reads plainly and
	 * releaseLock() is a no-op that always reports success
	 */
	public function getLockedAndReleaseLockArePlain(): bool
	{
		$store = $this->store();
		$store->set(['v'], 'x');
		
		return $store->getLocked(['v']) === 'x'
			&& $store->releaseLock(['v']) === true;
	}
	
	public function updateAppliesAndWritesBack(): bool
	{
		$store = $this->store();
		$store->set(['counter'], 1);
		
		$result = $store->update(['counter'],
			static fn(mixed $value): mixed => $value + 1);
		
		return $result === 2
			&& $store->get(['counter']) === 2;
	}
	
	public function updateReleasesOnFailure(): bool
	{
		$store = $this->store();
		$store->set(['v'], 'kept');
		
		try
		{
			$store->update(['v'], static function(): void
			{
				throw new RuntimeException('updater failed');
			});
			
			return false;
		}
		catch(RuntimeException)
		{
		}
		
		return $store->get(['v']) === 'kept';
	}
	
	/**
	 * Bound to a local array by reference (the $_SESSION mode): writes land
	 * in the bound array and external changes are visible through the store
	 */
	public function boundReferenceWritesThroughAndReadsBack(): bool
	{
		$backing = [];
		$store = new NativeStore($backing);
		
		$store->set(['user'], 'mg');
		$wroteThrough = ($backing['user'] ?? null) === 'mg';
		
		$backing['flag'] = true;
		
		return $wroteThrough === true
			&& $store->get(['flag']) === true;
	}
	
	/**
	 * A missing slot is auto-vivified as null, then written through the
	 * returned reference
	 */
	public function slotAutoVivifiesAndWritesThrough(): bool
	{
		$backing = [];
		$store = new NativeStore($backing);
		
		$slot = &$store->slot('ns');
		$vivified = array_key_exists('ns', $backing) === true
			&& $backing['ns'] === null;
		$slot = ['a' => 1];
		
		return $vivified === true
			&& $backing['ns'] === ['a' => 1]
			&& $store->get(['ns', 'a']) === 1;
	}
	
	/**
	 * An unbound (CLI) store has nothing to hand back - close() must be a
	 * no-op and never touch the native session machinery
	 */
	public function closeIsNoopWhenUnbound(): bool
	{
		$store = $this->store();
		$store->set(['a'], 1);
		
		$store->close();
		
		return $store->get(['a']) === 1;
	}
}
