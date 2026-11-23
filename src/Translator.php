<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Translator\Translation;
use Closure;
use IntlException;
use MessageFormatter;

use function array_key_exists;
use function chr;
use function explode;

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
	
	/**
	 * Missing handler: fn(Locale $locale, string $msgid, ?string $plural, ?string $context)
	 * Called by the translate methods when no loaded translation and no
	 * override holds the msgid (the test of has()). Its result is ignored:
	 * the text returned is the same with or without it. Inert when null.
	 */
	protected static ?Closure $missingHandler = null;
	
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
	
	public static function setMissingHandler(
		?Closure $handler,
	): void
	{
		self::$missingHandler = $handler;
	}
	
	public static function getMissingHandler(): ?Closure
	{
		return self::$missingHandler;
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
		return $this->getTranslation($this->lookUp(null, $phrase), ...$params);
	}
	
	public function translateContext(
		string $context,
		string $phrase,
		...$params,
	): string
	{
		return $this->getTranslation($this->lookUp($context, $phrase), ...$params);
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
		return $this->getTranslation(
			$this->lookUpPlural(null, $phraseSingular, $phrasePlural, $n),
			...$params,
		);
	}
	
	public function translatePluralContext(
		string $context,
		string $singular,
		string $plural,
		int $number,
		...$params,
	): string
	{
		return $this->getTranslation(
			$this->lookUpPlural($context, $singular, $plural, $number),
			...$params,
		);
	}
	
	/**
	 * Whether a loaded translation or an override holds the msgid, also when
	 * it is translated to its own text, which translate() cannot tell apart
	 * from a missing entry
	 */
	public function has(
		string $phrase,
		?string $plural = null,
		?string $context = null,
	): bool
	{
		$key = self::getMessageKey($phrase, $plural, $context);
		
		// an empty override is never applied, so it holds nothing
		return $this->isLoaded($key)
			|| ($this->getOverrides()[$key] ?? '') !== '';
	}
	
	/**
	 * Whether a loaded translation holds the raw key, whatever its translation
	 */
	protected function isLoaded(
		string $key,
	): bool
	{
		foreach($this->translations as $translation)
		{
			if($translation->exists($key))
			{
				return true;
			}
		}
		
		return false;
	}
	
	/**
	 * The raw gettext key of a msgid, as the .mo files and the overrides use it
	 */
	protected static function getMessageKey(
		string $phrase,
		?string $plural = null,
		?string $context = null,
	): string
	{
		$key = $plural === null
			? $phrase
			: $phrase . chr(0) . $plural;
		
		return $context === null
			? $key
			: $context . chr(4) . $key;
	}
	
	/**
	 * The phrase as the last translation path holding it has it, an override
	 * over all of them
	 */
	protected function lookUp(
		?string $context,
		string $phrase,
	): string
	{
		$translation = $phrase;
		$key = self::getMessageKey($phrase, context: $context);
		$held = false;
		
		foreach($this->translations as $translationAdapter)
		{
			// a path holding the msgid wins over the paths before it, also
			// with a translation that reads the same as the msgid
			if($translationAdapter->exists($key) === false)
			{
				continue;
			}
			
			$held = true;
			$result = $context === null
				? $translationAdapter->translate($phrase)
				: $translationAdapter->translateContext($context, $phrase);
			if($result !== '')
			{
				$translation = $result;
			}
		}
		
		$overrides = $this->getOverrides();
		if(($overrides[$key] ?? '') !== '')
		{
			$translation = $overrides[$key];
		}
		else if(self::$missingHandler !== null
			&& $held === false)
		{
			(self::$missingHandler)($this->locale, $phrase, null, $context);
		}
		
		return $translation;
	}
	
	/**
	 * The form for the number, from the last translation path holding the
	 * msgids, an override over all of them
	 */
	protected function lookUpPlural(
		?string $context,
		string $phraseSingular,
		string $phrasePlural,
		int $n,
	): string
	{
		// untranslated text stays English, so the English rule picks the form
		$translation = $n === 1
			? $phraseSingular
			: $phrasePlural;
		$key = self::getMessageKey($phraseSingular, $phrasePlural, $context);
		$held = false;
		
		foreach($this->translations as $translationAdapter)
		{
			// a path holding the msgids wins over the paths before it, also
			// with a form that reads the same as a msgid
			if($translationAdapter->exists($key) === false)
			{
				continue;
			}
			
			$held = true;
			$result = $context === null
				? $translationAdapter->translatePlural($phraseSingular, $phrasePlural, $n)
				: $translationAdapter->translatePluralContext($context, $phraseSingular, $phrasePlural, $n);
			if($result !== '')
			{
				$translation = $result;
			}
		}
		
		$overrides = $this->getOverrides();
		if(($overrides[$key] ?? '') !== '')
		{
			$list = explode(chr(0), $overrides[$key]);
			$translation = $list[$this->getPlural($n)] ?? $list[0];
		}
		else if(self::$missingHandler !== null
			&& $held === false)
		{
			(self::$missingHandler)($this->locale, $phraseSingular, $phrasePlural, $context);
		}
		
		return $translation;
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
