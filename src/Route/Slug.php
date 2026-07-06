<?php
declare(strict_types=1);

namespace Ovos\Route;

use Attribute;

/**
 * Slug
 *
 * Declares a model's SEO URL on the model itself - routes derive from
 * the data model the way controllers derive from the filesystem. The
 * URL shape is {prefix}/{slug},{id}{suffix}, e.g.
 * "news/leistung-ist-das-fundament,101600.html" - the id is
 * authoritative (indexed lookup), the slug is decorative and enforced
 * canonical (a drifted slug 301s to the stored one).
 *
 *   #[Route\Slug(
 *       prefix: 'news',
 *       dispatch: [News::class, 'article'],
 *       slug: 'url_title',
 *       id: 'id',
 *       suffix: '.html',
 *   )]
 *   class Article extends Model\Mysql
 *
 * @author Marcin Gil <mg@ovos.at>
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Slug
{
	/**
	 * @param string $prefix leading URL segment(s) before the slug
	 * @param array{0: string, 1: string} $dispatch [controllerClass, action]
	 * @param string $slug the model column holding the slug
	 * @param string $id the authoritative lookup column
	 * @param string $suffix trailing literal (".html", "" for none)
	 * @param bool $canonical 301 to the stored slug when the URL slug drifts
	 * @param string $separator between slug and id (",", "-", "/")
	 */
	public function __construct(
		public readonly string $prefix,
		public readonly array $dispatch,
		public readonly string $slug = 'slug',
		public readonly string $id = 'id',
		public readonly string $suffix = '.html',
		public readonly bool $canonical = true,
		public readonly string $separator = ',',
	)
	{
	}
}
