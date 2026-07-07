<?php
declare(strict_types=1);

namespace Tests\Cache;

use Ovos\Plugins\Cache\Page as Subject;
use Ovos\Request;
use Ovos\Test;

use function str_ends_with;
use function str_starts_with;

/**
 * Page cache - the pure key/gating logic (the plugin's dispatch behaviour is
 * exercised by a live request, not the unit runner)
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Page extends Test
{
	public function cacheKeyIsDeterministicAndPrefixed(): bool
	{
		$a = Subject::cacheKey('http', '/news/article/id/42');
		$b = Subject::cacheKey('http', '/news/article/id/42');
		
		return $a === $b
			&& str_starts_with($a, Subject::KEY_PREFIX) === true
			// the interface is part of the key
			&& $a !== Subject::cacheKey('cli', '/news/article/id/42');
	}
	
	public function canonicalUriSortsQueryAndDropsFragment(): bool
	{
		return Subject::canonicalUri('/list?b=2&a=1') === Subject::canonicalUri('/list?a=1&b=2')
			&& Subject::canonicalUri('/list?a=1&b=2') === '/list?a=1&b=2'
			&& Subject::canonicalUri('/list#top') === '/list'
			&& Subject::canonicalUri('/plain') === '/plain';
	}
	
	public function varyChangesTheKey(): bool
	{
		$guest = Subject::cacheKey('http', '/dash', ['auth' => 'guest']);
		$member = Subject::cacheKey('http', '/dash', ['auth' => 'member']);
		
		return $guest !== $member
			&& $guest !== Subject::cacheKey('http', '/dash');
	}
	
	public function onlyGetAndHeadAreCacheableRequests(): bool
	{
		return Subject::isCacheableRequest(Request::METHOD_GET) === true
			&& Subject::isCacheableRequest(Request::METHOD_HEAD) === true
			&& Subject::isCacheableRequest(Request::METHOD_POST) === false
			&& Subject::isCacheableRequest(Request::METHOD_QUERY) === false
			&& Subject::isCacheableRequest('') === false;
	}
	
	public function nullResponseIsNotCacheable(): bool
	{
		return Subject::isCacheableResponse(null) === false;
	}
	
	public function freshnessFollowsFreshUntil(): bool
	{
		$now = 1000;
		
		return Subject::isFresh([], $now) === true // no window - ttl is the clock
			&& Subject::isFresh(['freshUntil' => 1000], $now) === true // inclusive
			&& Subject::isFresh(['freshUntil' => 1001], $now) === true
			&& Subject::isFresh(['freshUntil' => 999], $now) === false;
	}
	
	public function etagIsStrongDeterministicAndBodySensitive(): bool
	{
		$a = Subject::etagFor('<html>page</html>');
		
		return $a === Subject::etagFor('<html>page</html>')
			&& $a !== Subject::etagFor('<html>other</html>')
			&& str_starts_with($a, '"') === true
			&& str_ends_with($a, '"') === true;
	}
}
