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
	 * @var Locale
	 */
	protected Locale $_locale;

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
		$this->_locale = $locale;
		
		$this->refreshTranslations();
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
					= new Translation($translationPath);
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
	 * @param string $phraseSingular
	 * @param string $phrasePlural
	 * @param int $n
	 * @param string ...$params
	 *
	 * @return string
	 */
	public function translatePlural(string $phraseSingular, string $phrasePlural, int $n, ...$params): string
	{
		$translation = null;

		foreach($this->_translations as $translationAdapter)
		{
			$result = $translationAdapter->translatePlural($phraseSingular, $phrasePlural, $n);

			// inheritance of translations (each consecutive translation overwrites the former)
			if($result !== ''
				&& ($translation !== null
					&& $result !== $translation
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
}
