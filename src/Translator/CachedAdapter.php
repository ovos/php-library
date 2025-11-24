<?php
declare(strict_types=1);

namespace Ovos\Translator;

use Ovos\ArrayObject;
use Ovos\Store\Apcu;

use function Ovos\services;
use function file_exists;
use function filemtime;
use function substr;
use function strlen;
use function implode;
use function str_contains;
use function array_key_exists;
use function explode;

/**
 * CachedTranslator
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class CachedAdapter
{
	/**
	 * @var Translation
	 */
	protected Translation $_translation;
	
	/**
	 * @var array
	 */
	protected array $_translations = [];
	
	/**
	 * @param Translation $translation
	 */
	public function __construct(Translation $translation)
	{
		$this->_translation = $translation;
		
		$filename = $translation->getPath();
		if(file_exists($filename) === false)
		{
			return;
		}
		
		$store = services()->cache->getPerishableStore();
		
		$mTime = filemtime($filename);
		$path = substr($filename, strlen(BASE_DIR));
		$cacheId = $store->pathToId($path); // a static method accessed from the instance
		
		if(($item = $store->get($cacheId))
			&& $item->mtime === $mTime)
		{
			$this->setTranslations($item->translations->getArrayCopy());
		}
		
		$parser = new MoParser($filename);
		$this->setTranslations($parser->getTranslations());
		
		$item = new ArrayObject;
		$item->mtime = $mTime;
		$item->translations = $this->getTranslations();
		
		$store->set($cacheId, $item);
	}
	
	/**
	 * Translates a string
	 *
	 * @param string $msgid String to be translated
	 *
	 * @return string translated string (or original, if not found)
	 */
	public function gettext(string $msgid): string
	{
		return $this->exists($msgid)
			? $this->_translations[$msgid] : $msgid;
	}
	
	/**
	 * Check if a string is translated
	 *
	 * @param string $msgid String to be checked
	 */
	public function exists(string $msgid): bool
	{
		return array_key_exists($msgid, $this->_translations);
	}
	
	/**
	 * Translate with context
	 *
	 * @param string $msgctxt Context
	 * @param string $msgid   String to be translated
	 *
	 * @return string translated plural form
	 */
	public function pgettext(string $msgctxt, string $msgid): string
	{
		$key = implode(chr(4), [$msgctxt, $msgid]);
		$ret = $this->gettext($key);
		if(str_contains($ret, chr(4)))
		{
			return $msgid;
		}
		
		return $ret;
	}
	
	/**
	 * Plural version of gettext
	 *
	 * @param string $msgid       Single form
	 * @param string $msgidPlural Plural form
	 * @param int    $number      Number of objects
	 *
	 * @return string translated plural form
	 */
	public function ngettext(string $msgid, string $msgidPlural, int $number): string
	{
		// this should contain all strings separated by NULLs
		$key = implode(chr(0), [$msgid, $msgidPlural]);
		if($this->exists($key))
		{
			return $number !== 1 ? $msgidPlural : $msgid;
		}
		
		$result = $this->gettext($key);
		
		// find out the appropriate form
		$select = $this->_translation->getTranslator()->getPlural($number);
		
		$list = explode(chr(0), $result);
		if(isset($list[$select]) === false)
		{
			return $list[0];
		}
		
		return $list[$select];
	}
	
	/**
	 * Plural version of pgettext.
	 *
	 * @param string $msgctxt     Context
	 * @param string $msgid       Single form
	 * @param string $msgidPlural Plural form
	 * @param int    $number      Number of objects
	 *
	 * @return string translated plural form
	 */
	public function npgettext(string $msgctxt, string $msgid, string $msgidPlural, int $number): string
	{
		$key = implode(chr(4), [$msgctxt, $msgid]);
		$ret = $this->ngettext($key, $msgidPlural, $number);
		if(str_contains($ret, chr(4)))
		{
			return $msgid;
		}
		
		return $ret;
	}
	
	/**
	 * @param array $translations
	 *
	 * @return static
	 */
	public function setTranslations(array $translations): static
	{
		$this->_translations = $translations;
		
		return $this;
	}
	
	/**
	 * @return array
	 */
	public function getTranslations(): array
	{
		return $this->_translations;
	}
}
