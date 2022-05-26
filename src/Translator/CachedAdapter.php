<?php
declare(strict_types=1);

namespace Ovos\Translator;

use Ovos\ArrayObject;
use Ovos\Locale;
use Ovos\Strings;
use Ovos\Translator;
use function file_exists;
use function filemtime;
use function substr;
use function strlen;
use function Ovos\services;

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
		if(($item = $store->get($cacheId))
			&& $item->mtime === $mTime)
		{
			$this->setTranslations($item->translations);
		}
		
		$parser = new MoParser($filename);
		$this->setTranslations($parser->getTranslations());

		$item = new ArrayObject;
		$item->mtime = $mTime;
		$item->translations = $this->getTranslations();
		
		$store->set($cacheId, $item);
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
		if(strpos($ret, chr(4)) !== false)
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
		// this should contains all strings separated by NULLs
		$key = implode(chr(0), [$msgid, $msgidPlural]);
		if($this->exists($key))
		{
			return $number !== 1 ? $msgidPlural : $msgid;
		}

		$result = $this->get($key);

		// find out the appropriate form
		$select = $this->getPlural($number, $this->_locale->symbol);

		$list = explode(chr(0), $result);
		if($list === false)
		{
			return '';
		}
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
		if(strpos($ret, chr(4)) !== false)
		{
			return $msgid;
		}

		return $ret;
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
	
	/**
	 * @see Zend_Translate_Plural
	 * Alternative is to parse and eval "Plural-Forms:" header in .mo file
	 * 
	 * Returns the plural definition to use
	 * 
	 * @param int $number
	 * @param string $locale
	 *
	 * @return int
	 */
	public static function getPlural(int $number, string $locale): int
	{
		if($locale === 'pt_BR') // exception for Brasil
		{
			$language = 'xbr';
		}
		else
		{
			$language = strstr($locale, '_', true);
		}
		
		switch($language)
		{
			case 'az':
			case 'bo':
			case 'dz':
			case 'id':
			case 'ja':
			case 'jv':
			case 'ka':
			case 'km':
			case 'kn':
			case 'ko':
			case 'ms':
			case 'th':
			case 'tr':
			case 'vi':
			case 'zh':
				return 0;
				break;

			case 'af':
			case 'bn':
			case 'bg':
			case 'ca':
			case 'da':
			case 'de':
			case 'el':
			case 'en':
			case 'eo':
			case 'es':
			case 'et':
			case 'eu':
			case 'fa':
			case 'fi':
			case 'fo':
			case 'fur':
			case 'fy':
			case 'gl':
			case 'gu':
			case 'ha':
			case 'he':
			case 'hu':
			case 'is':
			case 'it':
			case 'ku':
			case 'lb':
			case 'ml':
			case 'mn':
			case 'mr':
			case 'nah':
			case 'nb':
			case 'ne':
			case 'nl':
			case 'nn':
			case 'no':
			case 'om':
			case 'or':
			case 'pa':
			case 'pap':
			case 'ps':
			case 'pt':
			case 'so':
			case 'sq':
			case 'sv':
			case 'sw':
			case 'ta':
			case 'te':
			case 'tk':
			case 'ur':
			case 'zu':
				return ($number == 1) ? 0 : 1;

			case 'am':
			case 'bh':
			case 'fil':
			case 'fr':
			case 'gun':
			case 'hi':
			case 'ln':
			case 'mg':
			case 'nso':
			case 'xbr':
			case 'ti':
			case 'wa':
				return (($number == 0) || ($number == 1)) ? 0 : 1;

			case 'be':
			case 'bs':
			case 'hr':
			case 'ru':
			case 'sr':
			case 'uk':
				return (($number % 10 == 1) && ($number % 100 != 11)) ? 0 : ((($number % 10 >= 2) && ($number % 10 <= 4) && (($number % 100 < 10) || ($number % 100 >= 20))) ? 1 : 2);

			case 'cs':
			case 'sk':
				return ($number == 1) ? 0 : ((($number >= 2) && ($number <= 4)) ? 1 : 2);

			case 'ga':
				return ($number == 1) ? 0 : (($number == 2) ? 1 : 2);

			case 'lt':
				return (($number % 10 == 1) && ($number % 100 != 11)) ? 0 : ((($number % 10 >= 2) && (($number % 100 < 10) || ($number % 100 >= 20))) ? 1 : 2);

			case 'sl':
				return ($number % 100 == 1) ? 0 : (($number % 100 == 2) ? 1 : ((($number % 100 == 3) || ($number % 100 == 4)) ? 2 : 3));

			case 'mk':
				return ($number % 10 == 1) ? 0 : 1;

			case 'mt':
				return ($number == 1) ? 0 : ((($number == 0) || (($number % 100 > 1) && ($number % 100 < 11))) ? 1 : ((($number % 100 > 10) && ($number % 100 < 20)) ? 2 : 3));

			case 'lv':
				return ($number == 0) ? 0 : ((($number % 10 == 1) && ($number % 100 != 11)) ? 1 : 2);

			case 'pl':
				return ($number == 1) ? 0 : ((($number % 10 >= 2) && ($number % 10 <= 4) && (($number % 100 < 12) || ($number % 100 > 14))) ? 1 : 2);

			case 'cy':
				return ($number == 1) ? 0 : (($number == 2) ? 1 : ((($number == 8) || ($number == 11)) ? 2 : 3));

			case 'ro':
				return ($number == 1) ? 0 : ((($number == 0) || (($number % 100 > 0) && ($number % 100 < 20))) ? 1 : 2);

			case 'ar':
				return ($number == 0) ? 0 : (($number == 1) ? 1 : (($number == 2) ? 2 : ((($number >= 3) && ($number <= 10)) ? 3 : ((($number >= 11) && ($number <= 99)) ? 4 : 5))));

			default:
				return 0;
		}
	}
}
