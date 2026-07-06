<?php
declare(strict_types=1);

namespace Tests\Route;

use Ovos\ArrayObject;
use Ovos\Container\Inject;
use Ovos\Model\Mysql as Model;
use Ovos\Request;
use Ovos\Route\Slug;
use Ovos\Route\SlugResolver as Subject;
use Ovos\Store\Mysql as Store;
use Ovos\Test;
use Ovos\Test\Internal;
use Ovos\Url;
use Override;

/**
 * SlugResolver
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class SlugResolver extends Test
{
	#[Inject('config')]
	protected ArrayObject $config;
	
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
				(101600, "leistung-ist-das-fundament-unseres-wohlstands");
		');
	}
	
	protected function resolver(): Subject
	{
		// seed the routable-model list the way the app config would
		$this->config->system->routes = new ArrayObject([
			'slug' => new ArrayObject(['models' => [RouteArticle::class]]),
		]);
		
		return $this->container->injectClass(Subject::class);
	}
	
	protected function url(
		array $components,
	): Url
	{
		$url = new Url;
		$url->setComponents($components);
		
		return $url;
	}
	
	public function resolvesTheCanonicalUrl(): bool
	{
		$resolution = $this->resolver()->resolve(
			$this->url(['news',
				'leistung-ist-das-fundament-unseres-wohlstands,101600.html']),
			new HttpRequest,
		);
		
		return $resolution !== null
			&& $resolution->isRedirect() === false
			&& $resolution->controllerClass === 'News'
			&& $resolution->action === 'article'
			&& ($resolution->params[0] ?? null) instanceof RouteArticle
			&& (int)$resolution->params[0]->id === 101600;
	}
	
	public function redirectsWhenTheSlugDrifts(): bool
	{
		$resolution = $this->resolver()->resolve(
			$this->url(['news', 'stale-old-headline,101600.html']),
			new HttpRequest,
		);
		
		return $resolution !== null
			&& $resolution->isRedirect() === true
			&& $resolution->redirect instanceof Url
			&& (string)$resolution->redirect->getComponents()[1]
				=== 'leistung-ist-das-fundament-unseres-wohlstands,101600.html';
	}
	
	public function declinesUnknownPrefix(): bool
	{
		return $this->resolver()->resolve(
			$this->url(['blog', 'whatever,101600.html']),
			new HttpRequest,
		) === null;
	}
	
	public function declinesTheConventionShape(): bool
	{
		// no ",{id}.html" tail - a normal /news/article convention url
		return $this->resolver()->resolve(
			$this->url(['news', 'article']),
			new HttpRequest,
		) === null;
	}
	
	public function declinesMissingEntity(): bool
	{
		return $this->resolver()->resolve(
			$this->url(['news', 'ghost,999999.html']),
			new HttpRequest,
		) === null;
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

class HttpRequest extends Request
{
	#[Override]
	public function isCli(): bool
	{
		return false; // exercise the http path from the CLI test runner
	}
}

class RouteArticles extends Store
{
	public const ?string TABLE = 'tests_route_articles';
	public const ?string MODEL = RouteArticle::class;
}

#[Slug(
	prefix: 'news',
	dispatch: ['Controllers\News', 'article'],
	slug: 'url_title',
	id: 'id',
	suffix: '.html',
)]
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
