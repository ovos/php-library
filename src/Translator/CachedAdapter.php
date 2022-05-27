<?php
declare(strict_types=1);

namespace Ovos\Translator;

use Ovos\ArrayObject;
use Ovos\Locale;
use Ovos\Strings;

use function Ovos\services;
use function file_exists;
use function filemtime;
use function substr;
use function strlen;

/**
 * CachedTranslator
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class CachedAdapter
{
	/**
	 * @var Locale
	 */
	protected Locale $_locale;

	/**
	 * @var array
	 */
	protected array $_translations = [];
	
	/**
	 * @param Locale $locale
	 * @param string $filename
	 */
	public function __construct(Locale $locale, string $filename)
	{
		$this->setLocale($locale);
	
		if(file_exists($filename) === false)
		{
			return;
		}

		$mTime = filemtime($filename);
		$path = substr($filename, strlen(BASE_DIR));
		$cacheId = Strings::slugify($path);

		$store = services()->cache->getStore();
		if($store && ($item = $store->get($cacheId))
			&& $item->mtime === $mTime)
		{
			$this->setTranslations($item->translations);
		}
		
		$parser = new MoParser($filename);
		$this->setTranslations($parser->getTranslations());
		
		if($store)
		{
			$item = new ArrayObject;
			$item->mtime = $mTime;
			$item->translations = $this->getTranslations();
			
			$store->set($cacheId, $item);
		}
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
		$select = $this->getPlural($number);

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
	 * @see Zend_Translate_Plural
	 * Alternative is to parse and eval "Plural-Forms:" header in .mo file
	 * 
	 * Returns the plural definition to use
	 * 
	 * @param int $number
	 *
	 * @return int
	 */
	public function getPlural(int $number): int
	{
		$language = $this->_locale->language;
	
		if($this->_locale->symbol === 'pt_BR') // exception for Brasil
		{
			$language = 'xbr';
		}
		
		return match ($language)
		{
			'az', 'bo', 'dz', 'id', 'ja', 'jv', 'ka', 'km', 'kn', 'ko', 'ms', 'th', 'tr', 'vi', 'zh' => 0,
			'af', 'bn', 'bg', 'ca', 'da', 'de', 'el', 'en', 'eo', 'es', 'et', 'eu', 'fa', 'fi', 'fo', 'fur', 'fy', 'gl', 'gu', 'ha', 'he', 'hu', 'is', 'it', 'ku', 'lb', 'ml', 'mn', 'mr', 'nah', 'nb', 'ne', 'nl', 'nn', 'no', 'om', 'or', 'pa', 'pap', 'ps', 'pt', 'so', 'sq', 'sv', 'sw', 'ta', 'te', 'tk', 'ur', 'zu' => ($number === 1)
				? 0 : 1,
			'am', 'bh', 'fil', 'fr', 'gun', 'hi', 'ln', 'mg', 'nso', 'xbr', 'ti', 'wa' => (($number === 0) || ($number === 1))
				? 0 : 1,
			'be', 'bs', 'hr', 'ru', 'sr', 'uk' => (($number % 10 === 1) && ($number % 100 !== 11))
				? 0
				: ((($number % 10 >= 2) && ($number % 10 <= 4) && (($number % 100 < 10) || ($number % 100 >= 20)))
					? 1 : 2),
			'cs', 'sk' => ($number === 1) ? 0
				: ((($number >= 2) && ($number <= 4)) ? 1 : 2),
			'ga' => ($number === 1) ? 0 : (($number === 2) ? 1 : 2),
			'lt' => (($number % 10 === 1) && ($number % 100 !== 11))
				? 0
				: ((($number % 10 >= 2) && (($number % 100 < 10) || ($number % 100 >= 20)))
					? 1 : 2),
			'sl' => ($number % 100 === 1) ? 0
				: (($number % 100 === 2) ? 1
					: ((($number % 100 === 3) || ($number % 100 === 4)) ? 2
						: 3)),
			'mk' => ($number % 10 === 1) ? 0 : 1,
			'mt' => ($number === 1)
				? 0
				: ((($number === 0) || (($number % 100 > 1) && ($number % 100 < 11)))
					? 1
					: ((($number % 100 > 10) && ($number % 100 < 20)) ? 2 : 3)),
			'lv' => ($number === 0) ? 0
				: ((($number % 10 === 1) && ($number % 100 !== 11)) ? 1 : 2),
			'pl' => ($number === 1)
				? 0
				: ((($number % 10 >= 2) && ($number % 10 <= 4) && (($number % 100 < 12) || ($number % 100 > 14)))
					? 1 : 2),
			'cy' => ($number === 1) ? 0
				: (($number === 2) ? 1
					: ((($number === 8) || ($number === 11)) ? 2 : 3)),
			'ro' => ($number === 1)
				? 0
				: ((($number === 0) || (($number % 100 > 0) && ($number % 100 < 20)))
					? 1 : 2),
			'ar' => ($number === 0)
				? 0
				: (($number === 1)
					? 1
					: (($number === 2) ? 2
						: ((($number >= 3) && ($number <= 10)) ? 3
							: ((($number >= 11) && ($number <= 99)) ? 4 : 5)))),
			default => 0,
		};
	}
	
	/**
	 * @param Locale $locale
	 * 
	 * @return self
	 */
	public function setLocale(Locale $locale): self
	{
		$this->_locale = $locale;
		
		return $this;
	}
	
	/**
	 * @return Locale
	 */
	public function getLocale(): Locale
	{
		return $this->_locale;
	}
	
	/**
	 * @param array $translations
	 *
	 * @return $this
	 */
	public function setTranslations(array $translations): self
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
