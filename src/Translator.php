<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Translator\Translation;
use MessageFormatter;

/**
 * Translator
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Translator
{
	/**#@+
	 * Translation constants
	 */
	public const TRANSLATION_EXT = '.mo';
	/**#@-*/

	/**
	 * Locale of this instance
	 * 
	 * @var Locale
	 */
	protected Locale $_locale;
	
	/**
	 * Current locale instance to use for this response
	 * It's possible to use all locales and translate in many languages during the single request
	 * 
	 * @var ?Locale
	 */
	protected static ?Locale $_currentLocale = null;

	/**
	 * @var Translation[]
	 */
	protected array $_translations = [];
	
	/**
	 * @var array
	 */
	protected static array $_translationsPaths = [];
	
	/**
	 * @param Locale $locale
	 */
	public function __construct(Locale $locale)
	{
		$this->_locale = $locale; // translator for this specific locale
		
		$this->refreshTranslations();
	}
	
	/**
	 * @param Locale $locale
	 *
	 * @return void
	 */
	public static function setCurrentLocale(Locale $locale): void
	{
		self::$_currentLocale = $locale;
	}
	
	/**
	 * @return Locale
	 */
	public static function getCurrentLocale(): Locale
	{
		if(self::$_currentLocale === null)
		{
			self::$_currentLocale = Locales::getDefault();
		}
	
		return self::$_currentLocale;
	}
	
	/**
	 * @return self
	 */
	public function refreshTranslations(): self
	{
		foreach(self::$_translationsPaths as $translationsPath)
		{
			$translationPath = $translationsPath
				. $this->_locale->getLanguage()
				. self::TRANSLATION_EXT;
				
			if(array_key_exists($translationPath,
				$this->_translations) === false)
			{
				$this->_translations[$translationPath]
					= new Translation($this, $translationPath);
			}
		}
		
		return $this;
	}

	/**
	 * @return Translation[]
	 */
	public function getTranslations(): array
	{
		return $this->_translations;
	}

	/**
	 * @param string $phrase
	 * @param mixed ...$params
	 *
	 * @return string
	 */
	public function translate(string $phrase, ...$params): string
	{
		$translation = $phrase;
		
		foreach($this->_translations as $translationAdapter)
		{
			$result = $translationAdapter->translate($phrase);
			if($result !== ''
				&& $result !== $phrase)
			{
				$translation = $result;
			}
		}

		return $this->_getTranslation($translation, ...$params);
	}

	/**
	 * https://stackoverflow.com/questions/12184978/poedit-doesnt-recognize-n-plurals
	 * 
	 * @param string $phraseSingular
	 * @param string $phrasePlural
	 * @param int $n
	 * @param string ...$params
	 *
	 * @return string
	 */
	public function translatePlural(string $phraseSingular, string $phrasePlural, int $n, ...$params): string
	{
		$translation = $this->getPlural($n) === 0 // english
			? $phraseSingular : $phrasePlural;

		foreach($this->_translations as $translationAdapter)
		{
			$result = $translationAdapter->translatePlural($phraseSingular, $phrasePlural, $n);
			
			// inheritance of translations (each consecutive translation overwrites the former)
			if($result !== ''
				&& ($result !== $translation
					&& $result !== $phraseSingular
					&& $result !== $phrasePlural))
			{
				$translation = $result;
			}
		}

		return $this->_getTranslation($translation, ...$params);
	}

	/**
	 * @param string $translation
	 * @param mixed $params
	 *
	 * @return string
	 */
	protected function _getTranslation(string $translation, ...$params): string
	{
		if(empty($params))
		{
			return $translation;
		}
		
		$formatter = new MessageFormatter($this->_locale->getLanguage(), $translation);
		return $formatter->format($params);
	}
	
	/**
	 * @param string $path
	 *
	 * @return void
	 */
	public static function addTranslationsPath(string $path): void
	{
		self::$_translationsPaths[$path] = $path;
	}
	
	/**
	 * @return array
	 */
	public static function getTranslationsPaths(): array
	{
		return self::$_translationsPaths;
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
		
		return match($language)
		{
			'az', 'bo', 'dz', 'id', 'ja', 'jv', 'ka', 'km', 'kn', 'ko', 'ms', 'th', 'tr', 'vi', 'zh'
				=> 0,
			'af', 'bn', 'bg', 'ca', 'da', 'de', 'el', 'en', 'eo', 'es', 'et', 'eu', 'fa', 'fi', 'fo', 'fur', 'fy',
			'gl', 'gu', 'ha', 'he', 'hu', 'is', 'it', 'ku', 'lb', 'ml', 'mn', 'mr', 'nah', 'nb', 'ne', 'nl', 'nn',
			'no', 'om', 'or', 'pa', 'pap', 'ps', 'pt', 'so', 'sq', 'sv', 'sw', 'ta', 'te', 'tk', 'ur', 'zu'
				=> ($number === 1) ? 0 : 1,
			'am', 'bh', 'fil', 'fr', 'gun', 'hi', 'ln', 'mg', 'nso', 'xbr', 'ti', 'wa'
				=> (($number === 0) || ($number === 1)) ? 0 : 1,
			'be', 'bs', 'hr', 'ru', 'sr', 'uk'
				=> (($number % 10 === 1) && ($number % 100 !== 11)) ? 0
					: ((($number % 10 >= 2) && ($number % 10 <= 4) && (($number % 100 < 10) || ($number % 100 >= 20))) ? 1 : 2),
			'cs', 'sk'
				=> ($number === 1) ? 0
					: ((($number >= 2) && ($number <= 4)) ? 1 : 2),
			'ga'
				=> ($number === 1) ? 0 : (($number === 2) ? 1 : 2),
			'lt'
				=> (($number % 10 === 1) && ($number % 100 !== 11)) ? 0
					: ((($number % 10 >= 2) && (($number % 100 < 10) || ($number % 100 >= 20))) ? 1 : 2),
			'sl'
				=> ($number % 100 === 1) ? 0
					: (($number % 100 === 2) ? 1
						: ((($number % 100 === 3) || ($number % 100 === 4)) ? 2 : 3)),
			'mk'
				=> ($number % 10 === 1) ? 0 : 1,
			'mt'
				=> ($number === 1) ? 0
					: ((($number === 0) || (($number % 100 > 1) && ($number % 100 < 11))) ? 1
						: ((($number % 100 > 10) && ($number % 100 < 20)) ? 2 : 3)),
			'lv'
				=> ($number === 0) ? 0
					: ((($number % 10 === 1) && ($number % 100 !== 11)) ? 1 : 2),
			'pl'
				=> ($number === 1) ? 0
					: ((($number % 10 >= 2) && ($number % 10 <= 4) && (($number % 100 < 12) || ($number % 100 > 14))) ? 1 : 2),
			'cy'
				=> ($number === 1) ? 0
					: (($number === 2) ? 1
						: ((($number === 8) || ($number === 11)) ? 2 : 3)),
			'ro'
				=> ($number === 1) ? 0
					: ((($number === 0) || (($number % 100 > 0) && ($number % 100 < 20)))
						? 1 : 2),
			'ar'
				=> ($number === 0) ? 0
					: (($number === 1) ? 1
						: (($number === 2) ? 2
							: ((($number >= 3) && ($number <= 10)) ? 3
								: ((($number >= 11) && ($number <= 99)) ? 4 : 5)))),
			default => 0,
		};
	}
}
