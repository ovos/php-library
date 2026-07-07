<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Translator\Translation;
use Closure;
use IntlException;
use MessageFormatter;

/**
 * Translator
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Translator
{
	// Translations
	public const string TRANSLATION_EXT = '.mo';
	
	/**
	 * Locale of this instance
	 */
	protected Locale $locale;
	
	/**
	 * Current locale instance to use for this response
	 * It's possible to use all locales and translate in many languages during the single request
	 */
	protected static ?Locale $currentLocale = null;
	
	/**
	 * @var Translation[]
	 */
	protected array $translations = [];
	
	protected static array $translationsPaths = [];
	
	/**
	 * Overrides provider: fn(Locale $locale): array
	 * Maps raw gettext keys (context chr(4) and plural msgid chr(0) encodings,
	 * as in the .mo files) to msgstr strings (plural forms chr(0)-joined).
	 * Overrides have the highest priority - they are checked after the
	 * translations loop. Unlike that loop, an override equal to the msgid is
	 * applied, so the source text can be forced over a .mo translation.
	 * Inert when null.
	 */
	protected static ?Closure $overridesProvider = null;
	
	public function __construct(
		Locale $locale,
	)
	{
		$this->locale = $locale; // translator for this specific locale
		
		$this->refreshTranslations();
	}
	
	public static function setCurrentLocale(
		Locale $locale,
	): void
	{
		self::$currentLocale = $locale;
	}
	
	public static function getCurrentLocale(): Locale
	{
		if(self::$currentLocale === null)
		{
			self::$currentLocale = Locales::getDefault();
		}
		
		return self::$currentLocale;
	}
	
	public static function setOverridesProvider(
		?Closure $provider,
	): void
	{
		self::$overridesProvider = $provider;
	}
	
	public static function getOverridesProvider(): ?Closure
	{
		return self::$overridesProvider;
	}
	
	protected function getOverrides(): array
	{
		if(self::$overridesProvider === null)
		{
			return [];
		}
		
		return (self::$overridesProvider)($this->locale);
	}
	
	public function refreshTranslations(): static
	{
		foreach(self::$translationsPaths as $translationsPath)
		{
			$translationPath = $translationsPath
				. $this->locale->getLanguage()
				. self::TRANSLATION_EXT;
			
			if(array_key_exists($translationPath,
				$this->translations) === false)
			{
				$this->translations[$translationPath]
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
		return $this->translations;
	}
	
	public function translate(
		string $phrase,
		...$params,
	): string
	{
		$translation = $phrase;
		
		foreach($this->translations as $translationAdapter)
		{
			$result = $translationAdapter->translate($phrase);
			if($result !== ''
				&& $result !== $phrase)
			{
				$translation = $result;
			}
		}
		
		$overrides = $this->getOverrides();
		if(($overrides[$phrase] ?? '') !== '')
		{
			$translation = $overrides[$phrase];
		}
		
		return $this->getTranslation($translation, ...$params);
	}
	
	/**
	 * https://stackoverflow.com/questions/12184978/poedit-doesnt-recognize-n-plurals
	 */
	public function translatePlural(
		string $phraseSingular,
		string $phrasePlural,
		int $n,
		...$params,
	): string
	{
		$translation = $this->getPlural($n) === 0 // english
			? $phraseSingular
			: $phrasePlural;
		
		foreach($this->translations as $translationAdapter)
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
		
		$overrides = $this->getOverrides();
		$key = $phraseSingular . chr(0) . $phrasePlural;
		if(($overrides[$key] ?? '') !== '')
		{
			$list = explode(chr(0), $overrides[$key]);
			$translation = $list[$this->getPlural($n)] ?? $list[0];
		}
		
		return $this->getTranslation($translation, ...$params);
	}
	
	protected function getTranslation(
		string $translation,
		...$params,
	): string
	{
		if(empty($params))
		{
			return $translation;
		}
		
		try
		{
			$formatter = new MessageFormatter($this->locale->getLanguage(), $translation);
			$result = $formatter->format($params);
		}
		catch(IntlException)
		{
			return $translation; // invalid pattern: degrade to the unformatted phrase
		}
		
		return $result === false
			? $translation
			: $result;
	}
	
	public static function addTranslationsPath(
		string $path,
	): void
	{
		self::$translationsPaths[$path] = $path;
	}
	
	public static function getTranslationsPaths(): array
	{
		return self::$translationsPaths;
	}
	
	/**
	 * @see Zend_Translate_Plural
	 * An alternative is to parse and eval "Plural-Forms:" header in .mo file
	 *
	 * Returns the plural definition to use
	 */
	public function getPlural(
		int $number,
	): int
	{
		$language = $this->locale->language;
		
		if($this->locale->symbol === 'pt_BR') // exception for Brasil
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
