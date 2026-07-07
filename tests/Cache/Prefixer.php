<?php
declare(strict_types=1);

namespace Tests\Cache;

use Ovos\Cache\Prefixer as Subject;
use Ovos\Test;

/**
 * Prefixer - the key-layout contract under every cache, session and page
 * key. The null-fallback rule is what the group-less tag-invalidation bug
 * (PR #27) hinged on: a passed null prefix MUST fall back to the internal
 * one, never degrade to an empty base.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Prefixer extends Test
{
	public function internalPrefixApplies(): bool
	{
		$prefixer = new Subject('console');
		
		return $prefixer->prefix('items') === 'console:items'
			&& $prefixer->getPrefix() === 'console';
	}
	
	public function passedPrefixWinsOverInternal(): bool
	{
		$prefixer = new Subject('console');
		
		return $prefixer->prefix('key', 'console:tests') === 'console:tests:key';
	}
	
	public function nullPassedPrefixFallsBackToInternal(): bool
	{
		// the PR #27 semantics: null means "use mine", not "use nothing"
		$prefixer = new Subject('console');
		
		return $prefixer->prefix('tags', null) === 'console:tags';
	}
	
	public function noPrefixAtAllLeavesTheKeyBare(): bool
	{
		$prefixer = new Subject;
		
		return $prefixer->prefix('key') === 'key'
			&& $prefixer->getPrefix() === null;
	}
	
	public function customSeparator(): bool
	{
		$prefixer = new Subject('p');
		
		return $prefixer->prefix('key', null, '|') === 'p|key';
	}
}
