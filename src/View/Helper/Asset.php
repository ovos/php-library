<?php
declare(strict_types=1);

namespace Ovos\View\Helper;

use Ovos\Cache\Key\Normalizer;
use Ovos\Service\Cache;
use Ovos\Service\Events;
use Ovos\View\Helper;
use Ovos\Dir;
use ErrorException;

/**
 * Asset
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Asset extends Helper
{
	protected Cache $cacheService;
	
	protected string $asset;
	
	public function __construct()
	{
		parent::__construct();
		
		/** @var Cache $cacheService */
		$cacheService = $this->container
			->get(Cache::SYMBOL);
		$this->cacheService = $cacheService;
	}
	
	public function asset(
		string $asset,
	): static
	{
		$this->set($asset);
		
		return $this;
	}
	
	public function set(
		string $asset,
	): static
	{
		$this->asset = $asset;
		
		return $this;
	}
	
	/**
	 * Add timestamp with the last modification date, cache filemtime call in apcu
	 */
	public function __toString(): string
	{
		$filename = $this->getFilename();
		
		// fetch mtime from memory
		$store = $this->cacheService->getPerishable()
			->getStore();
		$cacheId = Normalizer::fromPath($this->asset); // a static method accessed from the instance
		if($mDate = $store->get($cacheId))
		{
			return $this->asset . '?' . $mDate;
		}
		
		try
		{
			$mTime = filemtime($filename);
			
			$mDate = date('Ymdhis', $mTime);
			$store->set($cacheId, $mDate);
			
			// return the versioned url on the miss too — falling through to
			// the bare path meant the first render after every cache clear
			// (i.e. every deploy) served unversioned asset urls
			return $this->asset . '?' . $mDate;
		}
		catch(ErrorException $exception)
		{
			$this->container
				->get(Events::SYMBOL)
				->add($exception);
		}
		
		return $this->asset;
	}
	
	public function getFilename(): string
	{
		return BASE_DIR . 'public'
			. DIRECTORY_SEPARATOR . Dir::preProcess($this->asset, true);
	}
}
