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
	protected $_path;

	/**
	 * @var CachedAdapter
	 */
	protected $_adapter;

	/**
	 * @param string $path
	 */
	public function __construct(string $path)
	{
		$this->_path = $path;
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
