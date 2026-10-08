<?php
declare(strict_types=1);

namespace Tests\Cache;

use Ovos\Application;
use Ovos\Cache\Page as PageAttribute;
use Ovos\Plugins\Cache\Page as Subject;
use Ovos\Request;
use Ovos\Test;
use Override;

use function str_ends_with;
use function str_starts_with;

/**
 * Page cache - the pure key/gating logic (the plugin's dispatch: PageFlow)
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
	
	/**
	 * RULE: with a query allowlist only the named parameters are part of the
	 * key - a tracking parameter or a cache-buster's ?x=random is left out,
	 * an array parameter counts by its name; none listed ([]) leaves the
	 * query out, null keeps every parameter
	 */
	public function aQueryAllowlistKeepsOnlyTheNamedParameters(): bool
	{
		return Subject::canonicalUri('/list?utm_source=a&page=2&fbclid=x', ['page']) === '/list?page=2'
			&& Subject::canonicalUri('/list?x=9&page=2&filter[a]=1', ['page', 'filter']) === Subject::canonicalUri('/list?filter[a]=1&page=2')
			&& Subject::canonicalUri('/list?page=2&utm_source=a', []) === '/list'
			&& Subject::canonicalUri('/list?b=2&a=1', null) === '/list?a=1&b=2'
			&& Subject::cacheKey('http', '/list?page=2&utm_source=a', query: ['page']) === Subject::cacheKey('http', '/list?utm_source=b&page=2', query: ['page'])
			&& Subject::cacheKey('http', '/list?page=2', query: ['page']) !== Subject::cacheKey('http', '/list?page=3', query: ['page']);
	}
	
	/**
	 * RULE: the plugin keys a page with its action's query allowlist
	 */
	public function theKeyFollowsTheActionsQueryAllowlist(): bool
	{
		$key = static function(string $uri, ?array $query): string
		{
			return (new class($uri, $query) extends Subject
			{
				public function __construct(
					string $uri,
					?array $query,
				)
				{
					$this->app = new class extends Application
					{
						public function __construct()
						{
						}
						
						#[Override]
						public function getInterface(): string
						{
							return 'http';
						}
					};
					$this->request = new class($uri) extends Request
					{
						public function __construct(
							protected string $testUri,
						)
						{
						}
						
						#[Override]
						public function getServer(
							string $name,
						): ?string
						{
							return $name === 'REQUEST_URI' ? $this->testUri : null;
						}
					};
					$this->attribute = new PageAttribute(ttl: 60, query: $query);
				}
				
				public function key(): string
				{
					return $this->buildKey();
				}
			})->key();
		};
		
		return $key('/list?page=2&utm_source=a', ['page']) === $key('/list?page=2&utm_source=b', ['page'])
			&& $key('/list?page=2&utm_source=a', ['page']) !== $key('/list?page=3&utm_source=a', ['page'])
			&& $key('/list?page=2&utm_source=a', null) !== $key('/list?page=2&utm_source=b', null);
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
	
	public function etagIsStrongDeterministicAndBodySensitive(): bool
	{
		$a = Subject::etagFor('<html>page</html>');
		
		return $a === Subject::etagFor('<html>page</html>')
			&& $a !== Subject::etagFor('<html>other</html>')
			&& str_starts_with($a, '"') === true
			&& str_ends_with($a, '"') === true;
	}
}
