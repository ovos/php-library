<?php
declare(strict_types=1);

namespace Ovos\View\Helper;

use Ovos\Service\Memory;
use Ovos\View\Helper;
use Ovos\Dir;
use Ovos\Services;
use SplFileObject;
use ErrorException;

/**
 * Asset
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Asset extends Helper
{
	/**
	 * @var Memory
	 */
	protected Memory $_memoryService;

	/**
	 * @var string
	 */
	protected string $_asset;
	
	/**
	 */
	public function __construct()
	{
		parent::__construct();
		
		$this->_memoryService = Services::getInstance()->get(Memory::SYMBOL);
	}
	
	/**
	 * @param string $asset
	 *
	 * @return $this
	 */
	public function asset(string $asset): self
	{
		$this->set($asset);
		
		return $this;
	}

	/**
	 * @param string $asset
	 *
	 * @return $this
	 */
	public function set($asset): self
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
		$mDate = null;
		
		// fetch mtime from memory
		$pool = $this->_memoryService->getPool();
		$cacheId = str_replace('/', '', $this->_asset);
		if($pool->hasItem($cacheId))
		{
			$item = $pool->getItem($cacheId);
			$mDate = $item->get();
		}
		
		if($mDate === null)
		{
			try
			{
				$mTime = filemtime($filename);
			}
			catch(ErrorException $exception)
			{
				services()->events->add($exception);
			}
			
			$mDate = date('Ymdhis', $mTime);
			$item = $pool->getItem($cacheId);
			$item->set($mDate);
			$pool->save($item);
		}
		
		if($mDate !== null)		
		{
			return $this->_asset . '?' . $mDate;
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
