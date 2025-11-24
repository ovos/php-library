<?php
declare(strict_types=1);

namespace Ovos\Translator;

use Ovos\Translator;

/**
 * Translation
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Translation
{
	/**
	 * @var Translator
	 */
	protected Translator $_translator;
	
	/**
	 * @var string
	 */
	protected string $_path;
	
	/**
	 * @var ?CachedAdapter
	 */
	protected ?CachedAdapter $_adapter = null;
	
	/**
	 * @param Translator $translator
	 * @param string $path
	 */
	public function __construct(Translator $translator, string $path)
	{
		$this->setTranslator($translator);
		$this->setPath($path);
	}
	
	/**
	 * @param string $path
	 * 
	 * @return static
	 */
	public function setPath(string $path): static
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
	 * @param Translator $translator
	 * 
	 * @return static
	 */
	public function setTranslator(Translator $translator): static
	{
		$this->_translator = $translator;
		
		return $this;
	}
	
	/**
	 * @return Translator
	 */
	public function getTranslator(): Translator
	{
		return $this->_translator;
	}
	
	/**
	 * @param string $phrase
	 *
	 * @return string
	 */
	public function translate(string $phrase): string
	{
		return $this->getAdapter()
			->gettext($phrase);
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
		return $this->getAdapter()
			->ngettext($phraseSingular, $phrasePlural, $n);
	}
	
	/**
	 * @return CachedAdapter
	 */
	public function getAdapter(): CachedAdapter
	{
		if($this->_adapter === null)
		{
			$this->_adapter = new CachedAdapter($this);
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
