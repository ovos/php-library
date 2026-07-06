<?php
declare(strict_types=1);

namespace Ovos\Route;

use Ovos\ArrayObject;
use Ovos\Container\Inject;
use Ovos\Controller;
use Ovos\Model\Mysql as Model;
use Ovos\Request;
use Ovos\Url;

use function array_slice;
use function count;
use function ctype_digit;
use function implode;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strrpos;
use function substr;

/**
 * SlugResolver
 *
 * Resolves SEO URLs of the shape {prefix}/{slug},{id}{suffix} declared
 * on models via #[Route\Slug]. The id is authoritative (indexed
 * lookup); a slug that has drifted from the stored one 301s to the
 * canonical URL. The ",{id}{suffix}" tail is a shape the convention
 * router never produces, so the two route spaces never collide.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class SlugResolver implements Resolver
{
	#[Inject('config')]
	protected ArrayObject $config;
	
	protected ?Slugs $slugs = null;
	
	public function resolve(
		Url $url,
		Request $request,
	): ?Resolution
	{
		if($request->isCli())
		{
			return null;
		}
		
		$components = $url->getComponents();
		if(count($components) < 2)
		{
			return null; // need at least a prefix segment + the slug segment
		}
		
		// the slug+id always lives in the LAST segment; everything before it
		// is the prefix (supports multi-segment prefixes like "shop/news")
		$last = $components[count($components) - 1];
		$prefix = implode('/', array_slice($components, 0, -1));
		
		$route = $this->slugs()->forPrefix($prefix);
		if($route === null)
		{
			return null;
		}
		
		/** @var Slug $slug */
		$slug = $route['slug'];
		
		$id = $this->extractId($last, $slug);
		if($id === null)
		{
			return null; // not the {slug},{id}{suffix} shape - decline
		}
		
		/** @var class-string<Model> $modelClass */
		$modelClass = $route['model'];
		$store = new ($modelClass::getStoreClass());
		
		// base-store lookup by the authoritative id - no dependency on the
		// optional Find trait; one indexed query
		$statement = $store->executeFind(where: [$slug->id => $id]);
		$model = $statement === false
			? false
			: $statement->fetchObject($modelClass);
		if($model instanceof Model === false)
		{
			return null; // no such entity - let the chain / 404 handle it
		}
		
		// canonical: the URL slug must equal the stored one, else 301
		if($slug->canonical === true)
		{
			$stored = (string)$model->{$slug->slug};
			$given = $this->extractSlug($last, $slug);
			if($stored !== '' && $stored !== $given)
			{
				return Resolution::redirect(
					$this->canonicalUrl($url, $components, $slug, $stored, $id));
			}
		}
		
		[$controllerClass, $action] = $slug->dispatch;
		
		return new Resolution(
			$this->relativeController($controllerClass),
			$action,
			[$model], // route-model binding: the action receives the entity
		);
	}
	
	/**
	 * The numeric id from "{slug},{id}{suffix}", or null when the segment
	 * is not that shape (wrong suffix, no separator, non-numeric id)
	 */
	protected function extractId(
		string $segment,
		Slug $slug,
	): ?int
	{
		if($slug->suffix !== ''
			&& str_ends_with($segment, $slug->suffix) === false)
		{
			return null;
		}
		
		$body = $slug->suffix === ''
			? $segment
			: substr($segment, 0, -strlen($slug->suffix));
			
		$position = strrpos($body, $slug->separator);
		if($position === false)
		{
			return null;
		}
		
		$id = substr($body, $position + strlen($slug->separator));
		
		return ctype_digit($id) === true && $id !== ''
			? (int)$id
			: null;
	}
	
	protected function extractSlug(
		string $segment,
		Slug $slug,
	): string
	{
		$body = $slug->suffix === ''
			? $segment
			: substr($segment, 0, -strlen($slug->suffix));
			
		$position = strrpos($body, $slug->separator);
		
		return $position === false
			? $body
			: substr($body, 0, $position);
	}
	
	/**
	 * The canonical URL for a redirect: same prefix, the STORED slug, the
	 * id and the suffix - built from the request Url so the base path,
	 * host and query are preserved
	 */
	protected function canonicalUrl(
		Url $url,
		array $components,
		Slug $slug,
		string $storedSlug,
		int $id,
	): Url
	{
		$last = $storedSlug . $slug->separator . $id . $slug->suffix;
		$components[count($components) - 1] = $last;
		
		$canonical = $url->getClone();
		$canonical->setComponents($components);
		
		return $canonical;
	}
	
	/**
	 * A dispatch controller FQCN ("Controllers\News") down to the name
	 * the Router/Dispatcher expects ("News")
	 */
	protected function relativeController(
		string $controllerClass,
	): string
	{
		return str_starts_with($controllerClass, Controller::NAMESPACE)
			? substr($controllerClass, strlen(Controller::NAMESPACE))
			: $controllerClass;
	}
	
	protected function slugs(): Slugs
	{
		if($this->slugs !== null)
		{
			return $this->slugs;
		}
		
		$models = $this->config
			->getPath(['system', 'routes', 'slug', 'models']);
		$models = $models instanceof ArrayObject
			? $models->getArrayCopy()
			: (array)($models ?? []);
			
		// reflection over a small, static model list is cheap - built once
		// per request (the resolver is a per-request singleton); nothing to
		// serialize into a cache and go stale
		return $this->slugs = new Slugs($models);
	}
}
