<?php
declare(strict_types=1);

namespace Ovos\Route;

use Attribute;

/**
 * Article
 *
 * Declares that a controller action takes an SEO "entity tail" as its last
 * URL segment: {slug},{id}{suffix} (e.g. "leistung,101600.html"). The
 * controller and action come from the convention router (locale-aware) —
 * this attribute only says WHICH model the tail binds and how the tail is
 * shaped. The id is authoritative (indexed lookup); the slug is decorative
 * and enforced canonical (a drifted slug 301s to the stored one).
 *
 *   class News extends Controller
 *   {
 *       #[Route\Article(model: Article::class, slug: 'url_title')]
 *       public function article(Article $article) { … }
 *   }
 *
 * Convention routes "/news/article/…"; the resolver sees the tail, loads the
 * Article by id and hands the entity to the action in place of the raw segment.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Article
{
	/**
	 * @param class-string $model the model class the tail binds to
	 * @param string $slug the model column holding the slug (canonical)
	 * @param string $id the authoritative lookup column
	 * @param string $suffix trailing literal (".html", "" for none)
	 * @param string $separator between slug and id (",", "-", …)
	 * @param bool $canonical 301 to the stored slug when the URL slug drifts
	 */
	public function __construct(
		public readonly string $model,
		public readonly string $slug = 'url_title',
		public readonly string $id = 'id',
		public readonly string $suffix = '.html',
		public readonly string $separator = ',',
		public readonly bool $canonical = true,
	)
	{
	}
}
