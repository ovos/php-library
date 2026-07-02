<?php
declare(strict_types=1);

namespace Tests\View\Helper;

use Ovos\Cache\Key\Normalizer;
use Ovos\Service\Cache;
use Ovos\Service\Events;
use Ovos\Test;
use Ovos\Test\Internal;
use Ovos\View\Helper\Asset as AssetHelper;
use Override;

use function date;
use function file_put_contents;
use function filemtime;
use function is_dir;
use function is_file;
use function mkdir;
use function str_contains;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * Asset view helper: mtime-based cache busting.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Asset extends Test
{
	/**
	 * @var string[] absolute paths of fixture files to remove
	 */
	protected array $files = [];
	
	/**
	 * @var string[] cache ids to drop so no mtime leaks between runs
	 */
	protected array $cacheIds = [];
	
	/**
	 * Regression: __toString() used to compute and cache the mtime on a miss
	 * but then fall through to the bare path — the first render after every
	 * cache clear served unversioned urls.
	 */
	public function versionsUrlOnFirstRender()
	{
		$relative = $this->makeAsset();
		$mTime = filemtime(BASE_DIR . 'public' . DIRECTORY_SEPARATOR . $relative);
		
		$url = (string)(new AssetHelper)->asset($relative);
		
		return $relative . '?' . date('Ymdhis', $mTime) === $url;
	}
	
	public function versionsUrlFromCachedMtime()
	{
		$relative = $this->makeAsset();
		
		$first = (string)(new AssetHelper)->asset($relative);
		$second = (string)(new AssetHelper)->asset($relative);
		
		return $first === $second
			&& str_contains($first, '?');
	}
	
	public function missingFileKeepsBarePath()
	{
		$relative = 'tests-asset-missing-' . uniqid() . '.css';
		$this->cacheIds[] = Normalizer::fromPath($relative);
		
		$events = $this->container->get(Events::SYMBOL);
		$before = $events->count();
		
		$url = (string)(new AssetHelper)->asset($relative);
		
		// the helper logs the stat failure instead of throwing — expected
		// here, so drain it or the suite exits non-zero over it
		$logged = $events->count() > $before;
		$events->clear();
		
		return $logged
			&& $relative === $url;
	}
	
	/**
	 * A fresh file under BASE_DIR/public, unique per call so no cached mtime
	 * applies. Returns the path relative to public/ — what templates pass.
	 */
	protected function makeAsset(): string
	{
		$dir = BASE_DIR . 'public';
		if(is_dir($dir) === false)
		{
			mkdir($dir, 0777, true);
		}
		
		$relative = 'tests-asset-' . uniqid() . '.css';
		$absolute = $dir . DIRECTORY_SEPARATOR . $relative;
		file_put_contents($absolute, '/* asset helper test */');
		
		$this->files[] = $absolute;
		$this->cacheIds[] = Normalizer::fromPath($relative);
		
		return $relative;
	}
	
	#[Internal]
	#[Override]
	public function deconstruct(): void
	{
		foreach($this->files as $file)
		{
			if(is_file($file))
			{
				unlink($file);
			}
		}
		
		$store = $this->container->get(Cache::SYMBOL)
			->getPerishable()
			->getStore();
		foreach($this->cacheIds as $cacheId)
		{
			$store->delete($cacheId);
		}
	}
}
