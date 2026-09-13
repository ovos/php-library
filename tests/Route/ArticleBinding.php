<?php
declare(strict_types=1);

namespace Tests\Route;

use Ovos\Exception\NotFoundException;
use Ovos\Model\Mysql as Model;
use Ovos\Route\Article;
use Ovos\Route\ArticleBinder;
use Ovos\Store\Mysql as Store;
use Ovos\Test;
use Ovos\Test\Internal;
use Ovos\Url;
use Override;

/**
 * ArticleBinding — the #[Route\Article] entity-tail binder: a "{slug},{id}{suffix}"
 * last segment resolves by id, 301s on slug drift, and 404s on a missing entity.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class ArticleBinding extends Test
{
	protected object $store;

	public function __construct()
	{
		$this->store = new RouteArticles;

		$this->store->source()->exec('
			CREATE TABLE IF NOT EXISTS tests_route_articles (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				url_title VARCHAR(255) NULL,
				PRIMARY KEY (id)
			)
			ENGINE = InnoDB;
		');
		$this->store->source()->exec('
			INSERT INTO tests_route_articles (id, url_title) VALUES
				(4711, "der-lange-weg-zur-kurzen-antwort");
		');
	}

	protected function attribute(): Article
	{
		return new Article(
			model: RouteArticle::class,
			slug: 'url_title',
		);
	}

	protected function url(
		array $components,
	): Url
	{
		$url = new Url;
		$url->setComponents($components);

		return $url;
	}

	public function bindsTheEntityById(): bool
	{
		$bound = (new ArticleBinder)->bind(
			$this->url(['news', 'article',
				'der-lange-weg-zur-kurzen-antwort,4711.html']),
			'der-lange-weg-zur-kurzen-antwort,4711.html',
			$this->attribute(),
		);

		return $bound instanceof RouteArticle
			&& (int)$bound->id === 4711;
	}

	public function redirectsWhenTheSlugDrifts(): bool
	{
		$bound = (new ArticleBinder)->bind(
			$this->url(['news', 'article', 'stale-old-headline,4711.html']),
			'stale-old-headline,4711.html',
			$this->attribute(),
		);

		return $bound instanceof Url
			&& (string)$bound->getComponents()[2]
				=== 'der-lange-weg-zur-kurzen-antwort,4711.html';
	}

	public function declinesWhenNotATail(): bool
	{
		// a normal convention segment — no ",{id}.html" tail to bind
		return (new ArticleBinder)->bind(
			$this->url(['news', 'article']),
			'article',
			$this->attribute(),
		) === null;
	}

	public function throwsWhenTheEntityIsMissing(): bool
	{
		try
		{
			(new ArticleBinder)->bind(
				$this->url(['news', 'article', 'ghost,999999.html']),
				'ghost,999999.html',
				$this->attribute(),
			);
		}
		catch(NotFoundException)
		{
			return true;
		}

		return false;
	}

	public function honoursCustomSuffixAndSeparator(): bool
	{
		$article = new Article(
			model: RouteArticle::class,
			slug: 'url_title',
			suffix: '',
			separator: '-',
		);

		$bound = (new ArticleBinder)->bind(
			$this->url(['news',
				'der-lange-weg-zur-kurzen-antwort-4711']),
			'der-lange-weg-zur-kurzen-antwort-4711',
			$article,
		);

		return $bound instanceof RouteArticle
			&& (int)$bound->id === 4711;
	}

	/**
	 * Called by the runner after all test methods
	 */
	#[Internal]
	#[Override]
	public function deconstruct(): void
	{
		$this->store->source()
			->exec('DROP TABLE IF EXISTS tests_route_articles');
	}
}

class RouteArticles extends Store
{
	public const ?string TABLE = 'tests_route_articles';
	public const ?string MODEL = RouteArticle::class;
}

class RouteArticle extends Model
{
	public ?int $id = null;
	public ?string $url_title = null;

	#[Override]
	public static function getStoreClass(): string
	{
		return RouteArticles::class;
	}
}
