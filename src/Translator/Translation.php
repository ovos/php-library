<?php
declare(strict_types=1);

namespace Ovos\Translator;

use Ovos\Cache;
use Ovos\Locale;
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
	 * @var Locale
	 */
	protected Locale $_locale;
	
	/**
	 * @var string
	 */
	protected string $_path;

	/**
	 * @var ?CachedAdapter
	 */
	protected ?CachedAdapter $_adapter = null;
	
	/**
	 * @param Locale $locale
	 * @param string $path
	 */
	public function __construct(Locale $locale, string $path)
	{
		$this->setLocale($locale);
		$this->setPath($path);
	}
	
	/**
	 * @param string $path
	 * 
	 * @return self
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
	 * @param string $locale
	 * 
	 * @return self
	 */
	public function setLocale(string $locale): self
	{
		$this->_locale = $locale;
		
		return $this;
	}
	
	/**
	 * @return string
	 */
	public function getLocale(): string
	{
		return $this->_locale;
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
			$this->_adapter = new CachedAdapter($this->_locale, $this->_path);
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
