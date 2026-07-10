<?php
declare(strict_types=1);

namespace Ovos\Route;

use Ovos\Exception\NotFoundException;
use Ovos\Model\Mysql as Model;
use Ovos\Url;

use function count;
use function ctype_digit;
use function str_ends_with;
use function strlen;
use function strrpos;
use function substr;

/**
 * ArticleBinder
 *
 * The reusable core behind #[Route\Article]: given the request Url, its last
 * segment and the attribute, it parses the "{slug},{id}{suffix}" tail, loads
 * the entity by its authoritative id and enforces the canonical slug.
 *
 * Returns one of:
 *   - Model — the loaded entity (bind it to the action in place of the segment)
 *   - Url   — a canonical redirect target (the slug drifted; caller sends a 301)
 *   - null  — the segment is not the tail shape (no binding; leave it as a param)
 *
 * A tail whose id resolves to no row throws NotFoundException — a detail URL
 * for a missing entity is a 404, not a decline.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
final class ArticleBinder
{
	public function bind(
		Url $url,
		string $segment,
		Article $article,
	): Model|Url|null
	{
		$id = $this->extractId($segment, $article);
		if($id === null)
		{
			return null; // not the {slug},{id}{suffix} shape — not our tail
		}

		/** @var class-string<Model> $modelClass */
		$modelClass = $article->model;
		$store = new ($modelClass::getStoreClass());

		// authoritative lookup by the indexed id — no dependency on the
		// optional Find trait
		$statement = $store->executeFind(where: [$article->id => $id]);
		$model = $statement === false
			? false
			: $statement->fetchObject($modelClass);
		if($model instanceof Model === false)
		{
			throw new NotFoundException(
				'No %s for id %s', $modelClass, (string)$id);
		}
		
		// canonical: the URL slug must equal the stored one, else 301
		if($article->canonical === true)
		{
			$stored = (string)$model->{$article->slug};
			$given = $this->extractSlug($segment, $article);
			if($stored !== '' && $stored !== $given)
			{
				return $this->canonicalUrl($url, $article, $stored, $id);
			}
		}
		
		return $model;
	}
	
	/**
	 * The numeric id from "{slug},{id}{suffix}", or null when the segment is
	 * not that shape (wrong suffix, no separator, non-numeric id)
	 */
	protected function extractId(
		string $segment,
		Article $article,
	): ?int
	{
		if($article->suffix !== ''
			&& str_ends_with($segment, $article->suffix) === false)
		{
			return null;
		}
		
		$body = $article->suffix === ''
			? $segment
			: substr($segment, 0, -strlen($article->suffix));
		
		$position = strrpos($body, $article->separator);
		if($position === false)
		{
			return null;
		}
		
		$id = substr($body, $position + strlen($article->separator));
		
		return ctype_digit($id) === true && $id !== ''
			? (int)$id
			: null;
	}
	
	protected function extractSlug(
		string $segment,
		Article $article,
	): string
	{
		$body = $article->suffix === ''
			? $segment
			: substr($segment, 0, -strlen($article->suffix));
		
		$position = strrpos($body, $article->separator);
		
		return $position === false
			? $body
			: substr($body, 0, $position);
	}
	
	/**
	 * The canonical URL for a redirect: same path, the STORED slug in the tail.
	 * Only the last component is rewritten, so locale / controller / action are
	 * preserved.
	 */
	protected function canonicalUrl(
		Url $url,
		Article $article,
		string $storedSlug,
		int $id,
	): Url
	{
		$components = $url->getComponents();
		$components[count($components) - 1]
			= $storedSlug . $article->separator . $id . $article->suffix;
		
		$canonical = $url->getClone();
		$canonical->setComponents($components);
		
		return $canonical;
	}
}
