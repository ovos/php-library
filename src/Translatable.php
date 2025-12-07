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
	public function _(
		string $phrase,
		...$params,
	): string
	{
		return Translator::getCurrentLocale()
			->getTranslator()
			->translate($phrase, ...$params);
	}
	
	public function _n(
		string $phraseSingular,
		string $phrasePlural,
		int $n,
		...$params,
	): string
	{
		return Translator::getCurrentLocale()
			->getTranslator()
			->translatePlural(
				$phraseSingular,
				$phrasePlural,
				$n,
				...$params,
			);
	}
}
