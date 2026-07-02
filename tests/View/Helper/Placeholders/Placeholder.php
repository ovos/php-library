<?php
declare(strict_types=1);

namespace Tests\View\Helper\Placeholders;

use Ovos\Cache\Key\Normalizer;
use Ovos\Service\Cache;
use Ovos\Test;
use Ovos\Test\Internal;
use Ovos\View\Helper\Placeholders\Placeholder as BasePlaceholder;
use Override;

use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function str_contains;
use function substr_count;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * Placeholder view helper: placements + script includes.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Placeholder extends Test
{
	/**
	 * @var string[] absolute paths of fixture files to remove
	 */
	protected array $files = [];
	
	/**
	 * @var string[] cache ids to drop so no mtime leaks between runs
	 */
	protected array $cacheIds = [];
	
	public function placementReplacePrependAppend()
	{
		$placeholder = new BasePlaceholder;
		$placeholder->set('B');
		$placeholder->set('A', BasePlaceholder::PLACEMENT_PREPEND);
		$placeholder->set('C', BasePlaceholder::PLACEMENT_APPEND);
		
		return 'ABC' === $placeholder->getValue();
	}
	
	public function includeScriptRendersTemplate()
	{
		$placeholder = new BasePlaceholder;
		$placeholder->includeScript('scripts/app.js',
			template: '<script type="module" src="%s"></script>');
		
		return '<script type="module" src="scripts/app.js"></script>'
			=== $placeholder->getValue();
	}
	
	public function includeScriptDedupsRepeats()
	{
		$placeholder = new BasePlaceholder;
		$placeholder
			->includeScript('scripts/app.js')
			->includeScript('scripts/app.js');
		
		return substr_count((string)$placeholder->getValue(), 'scripts/app.js') === 1;
	}
	
	/**
	 * Regression: View::asset() returns the shared Asset helper instance, and
	 * storing that object in the dedup list made every later asset-included
	 * script strict-compare equal to it — silently dropped as a "duplicate".
	 */
	public function includeScriptAssetKeepsEveryScript()
	{
		$first = $this->makeAsset();
		$second = $this->makeAsset();
		
		$placeholder = new BasePlaceholder;
		$placeholder
			->includeScript($first, asset: true)
			->includeScript($second, asset: true);
		
		$value = (string)$placeholder->getValue();
		
		return str_contains($value, $first . '?')
			&& str_contains($value, $second . '?');
	}
	
	public function includeScriptAssetStillDedups()
	{
		$script = $this->makeAsset();
		
		$placeholder = new BasePlaceholder;
		$placeholder
			->includeScript($script, asset: true)
			->includeScript($script, asset: true);
		
		return substr_count((string)$placeholder->getValue(), $script) === 1;
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
		
		$relative = 'tests-asset-' . uniqid() . '.js';
		$absolute = $dir . DIRECTORY_SEPARATOR . $relative;
		file_put_contents($absolute, '/* placeholder helper test */');
		
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
