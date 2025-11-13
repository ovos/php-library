<?php
declare(strict_types=1);

namespace Ovos\View\Helper;

use Ovos\Service\Cache;
use Ovos\Service\Memory;
use Ovos\Store\Apcu;
use Ovos\View\Helper;
use Ovos\Dir;
use Ovos\Services;
use ErrorException;

use function Ovos\services;

/**
 * Asset
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Asset extends Helper
{
	/**
	 * @var Cache
	 */
	protected Cache $_cacheService;
	
	/**
	 * @var string
	 */
	protected string $_asset;
	
	/**
	 */
	public function __construct()
	{
		parent::__construct();
		
		/** @var Memory $cacheService */
		$cacheService = $this->_app->getServices()->get(Cache::SYMBOL);
		$this->_cacheService = $cacheService;
	}
	
	/**
	 * @param string $asset
	 *
	 * @return self
	 */
	public function asset(string $asset): self
	{
		$this->set($asset);
		
		return $this;
	}
	
	/**
	 * @param string $asset
	 *
	 * @return self
	 */
	public function set(string $asset): self
	{
		$this->_asset = $asset;
		
		return $this;
	}
	
	/**
	 * Add timestamp with last modification date, cache filemtime call in apcu
	 * 
	 * @return string
	 */
	public function __toString(): string
	{
		$filename = $this->getFilename();
		
		// fetch mtime from memory
		$store = $this->_cacheService->getPerishableStore();
		$cacheId = $store->pathToId($this->_asset); // a static method accessed from the instance
		if($mDate = $store->get($cacheId))
		{
			return $this->_asset . '?' . $mDate;
		}
		
		try
		{
			$mTime = filemtime($filename);
			
			$mDate = date('Ymdhis', $mTime);
			$store->set($cacheId, $mDate);
		}
		catch(ErrorException $exception)
		{
			services()->events->add($exception);
		}
		
		return $this->_asset;
	}
	
	/**
	 * @return string
	 */
	public function getFilename(): string
	{
		return BASE_DIR . 'public' . DIRECTORY_SEPARATOR
			. Dir::preProcess($this->_asset, true);
	}
}
