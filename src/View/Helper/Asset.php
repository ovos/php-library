<?php
declare(strict_types=1);

namespace Ovos\View\Helper;

use Ovos\Service\Memory;
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
		$mDate = null;
		
		// fetch mtime from memory
		$store = $this->_memoryService->getStore();
		$cacheId = str_replace('/', '', $this->_asset);
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
