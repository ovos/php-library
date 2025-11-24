<?php
declare(strict_types=1);

namespace Ovos;

/**
 * Translatable
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
trait Translatable
{
	/**
	 * @param string $phrase
	 * @param mixed ...$params
	 *
	 * @return string
	 */
	public function _(string $phrase, ...$params): string
	{
		return Translator::getCurrentLocale()->getTranslator()
			->translate($phrase, ...$params);
	}
	
	/**
	 * @param string $phraseSingular
	 * @param string $phrasePlural
	 * @param int $n
	 * @param mixed ...$params
	 *
	 * @return string
	 */
	public function _n(string $phraseSingular, string $phrasePlural, int $n, ...$params): string
	{
		return Translator::getCurrentLocale()->getTranslator()
			->translatePlural($phraseSingular, $phrasePlural, $n, ...$params);
	}
}
