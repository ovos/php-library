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
	/**
	 * @var Locale
	 */
	protected $_locale;

	/**
	 * @var Translation[]
	 */
	protected $_translations = [];

	/**
	 * @param Locale $locale
	 */
	public function __construct(Locale $locale)
	{
		$this->_locale = $locale;
	}

	/**
	 * @param string|Translation $translation
	 *
	 * @return $this
	 */
	public function addTranslation($translation): self
	{
		$this->_translations[] = $translation;

		return $this;
	}

	/**
	 * @param string $path
	 *
	 * @return $this
	 */
	public function addTranslationPath(string $path): self
	{
		$this->_translations[] = new Translation($path . $this->_locale->getLanguage() . '.mo');

		return $this;
	}

	/**
	 * @return Translation[]
	 */
	public function getPaths(): array
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

		return $this->getTranslation($translation, ...$params);
	}

	/**
	 * @param string $translation
	 * @param mixed $params
	 *
	 * @return string
	 */
	protected function getTranslation(string $translation, ...$params): string
	{
		if(empty($params))
		{
			return $translation;
		}
		
		$formatter = new MessageFormatter($this->_locale->getLanguage(), $translation);
		return $formatter->format($params);
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

		return $this->getTranslation($translation, ...$params);
	}
}
