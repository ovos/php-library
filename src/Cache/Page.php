<?php
declare(strict_types=1);

namespace Ovos\Cache;

use Attribute;

/**
 * Page
 *
 * Marks a controller action whose full HTML response may be cached and
 * replayed. The Plugins\Cache\Page plugin reads this attribute off the
 * dispatched action, builds a key from the route (plus any vary axes) and
 * serves a stored body on a hit / captures it on a miss.
 *
 *   #[Cache\Page(ttl: 300, tags: ['articles'], vary: ['locale'], query: ['page'])]
 *   public function view(Article $article): Response
 *
 * @author Marcin Gil <mg@ovos.at>
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Page
{
	/**
	 * @param int $ttl seconds the cached response counts as FRESH; 0 = the store's default
	 * @param string[] $tags invalidation tags carried by the stored page
	 * @param string[] $vary axes the key varies on (e.g. 'locale', 'auth', 'query')
	 * @param int $stale extra seconds a stale page may still be served while
	 *   ONE request revalidates it (stale-while-revalidate) - visitors get the
	 *   stale shell instantly instead of waiting on a rebuild; requires ttl > 0
	 * @param int $apcu seconds to ALSO keep the record in per-worker APCu, in
	 *   front of Redis - zero network round trips on the hottest shells. Keep
	 *   it SHORT (single-digit seconds): a tag invalidation reaches this tier
	 *   only when the APCu ttl runs out
	 * @param ?string[] $query the query parameters that change the page - only
	 *   they are part of the key, every other one (utm_*, fbclid, a cache-
	 *   buster's ?x=random) is left out, so it neither splits the cache nor
	 *   forces a render; an array parameter (filter[a]=1) counts by its name.
	 *   [] leaves the query out; null (the default) keeps every parameter
	 */
	public function __construct(
		public int $ttl = 0,
		public array $tags = [],
		public array $vary = [],
		public int $stale = 0,
		public int $apcu = 0,
		public ?array $query = null,
	)
	{
	}
}
