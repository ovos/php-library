<?php
declare(strict_types=1);

namespace Ovos\Translator;

use Ovos\Cache;
use Ovos\Translator;
use Ovos\Translator\CachedAdapter;

/**
 * Translation
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Translation
{
	/**
	 * @var string
	 */
	protected string $_path;

	/**
	 * @var null|CachedAdapter
	 */
	protected null|CachedAdapter $_adapter = null;

	/**
	 * @param string $path
	 */
	public function __construct(string $path)
	{
		$this->setPath($path);
	}
	
	/**
	 * @param string $path
	 * 
	 * @return $this
	 */
	public function setPath(string $path): self
	{
		$this->_path = $path;
		
		return $this;
	}
	
	/**
	 * @return string
	 */
	public function getPath(): string
	{
		return $this->_path;
	}

	/**
	 * @param string $phrase
	 *
	 * @return string
	 */
	public function translate(string $phrase): string
	{
		return $this->getAdapter()->gettext($phrase);
	}

	/**
	 * @param string $phraseSingular
	 * @param string $phrasePlural
	 * @param int $n
	 *
	 * @return string
	 */
	public function translatePlural(string $phraseSingular, string $phrasePlural, int $n): string
	{
		return $this->getAdapter()->ngettext($phraseSingular, $phrasePlural, $n);
	}

	/**
	 * @return CachedAdapter
	 */
	public function getAdapter(): CachedAdapter
	{
		if($this->_adapter === null)
		{
			$this->_adapter = new CachedAdapter($this->_path);
		}

		return $this->_adapter;
	}

	/**
	 * @return string
	 */
	public function __toString(): string
	{
		return $this->_path;
	}
}
