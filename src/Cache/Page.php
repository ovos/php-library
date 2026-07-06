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
 *   #[Cache\Page(ttl: 300, tags: ['articles'], vary: ['locale'])]
 *   public function view(Article $article): Response
 *
 * @author Marcin Gil <mg@ovos.at>
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Page
{
	/**
	 * @param int $ttl seconds to keep the cached response; 0 = the store's default
	 * @param string[] $tags invalidation tags carried by the stored page
	 * @param string[] $vary axes the key varies on (e.g. 'locale', 'auth', 'query')
	 */
	public function __construct(
		public int $ttl = 0,
		public array $tags = [],
		public array $vary = [],
	)
	{
	}
}
