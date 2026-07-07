<?php
declare(strict_types=1);

namespace Tests;

use Ovos\Locale;
use Ovos\Test;
use Ovos\Translator as Subject;

/**
 * Translator - the overrides provider layered over the .mo translations
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Translator extends Test
{
	protected function getLocale(): Locale
	{
		return (new Locale('de'))
			->setSymbol('de_AT')
			->setLanguage('de');
	}
	
	public function isInertWithoutProvider(): bool
	{
		Subject::setOverridesProvider(null);
		
		$phrase = 'tests.translator.untranslated phrase';
		
		return (new Subject($this->getLocale()))->translate($phrase) === $phrase
			&& Subject::getOverridesProvider() === null;
	}
	
	public function appliesASingularOverride(): bool
	{
		$phrase = 'tests.translator.hello';
		
		Subject::setOverridesProvider(
			static fn(Locale $locale): array => [$phrase => 'überschrieben']);
		
		$result = (new Subject($this->getLocale()))->translate($phrase);
		Subject::setOverridesProvider(null);
		
		return $result === 'überschrieben';
	}
	
	public function passesTheLocaleToTheProvider(): bool
	{
		$language = null;
		Subject::setOverridesProvider(
			static function(Locale $locale) use (&$language): array
			{
				$language = $locale->getLanguage();
				
				return [];
			});
		
		(new Subject($this->getLocale()))->translate('tests.translator.locale probe');
		Subject::setOverridesProvider(null);
		
		return $language === 'de';
	}
	
	public function ignoresAnEmptyOverride(): bool
	{
		$phrase = 'tests.translator.empty override';
		
		Subject::setOverridesProvider(
			static fn(Locale $locale): array => [$phrase => '']);
		
		$result = (new Subject($this->getLocale()))->translate($phrase);
		Subject::setOverridesProvider(null);
		
		return $result === $phrase;
	}
	
	public function formatsOverrideParams(): bool
	{
		$phrase = 'tests.translator.hello {0}';
		
		Subject::setOverridesProvider(
			static fn(Locale $locale): array => [$phrase => 'Hallo {0}!']);
		
		$result = (new Subject($this->getLocale()))->translate($phrase, 'Welt');
		Subject::setOverridesProvider(null);
		
		return $result === 'Hallo Welt!';
	}
	
	public function picksThePluralOverrideFormByNumber(): bool
	{
		$singular = 'tests.translator.{0} file';
		$plural = 'tests.translator.{0} files';
		
		Subject::setOverridesProvider(
			static fn(Locale $locale): array => [
				$singular . chr(0) . $plural
					=> 'eine Datei' . chr(0) . '{0} Dateien',
			]);
		
		$subject = new Subject($this->getLocale());
		$one = $subject->translatePlural($singular, $plural, 1, 1);
		$many = $subject->translatePlural($singular, $plural, 5, 5);
		Subject::setOverridesProvider(null);
		
		return $one === 'eine Datei'
			&& $many === '5 Dateien';
	}
	
	public function fallsBackToTheFirstPluralForm(): bool
	{
		$singular = 'tests.translator.{0} entry';
		$plural = 'tests.translator.{0} entries';
		
		Subject::setOverridesProvider(
			static fn(Locale $locale): array => [
				$singular . chr(0) . $plural => '{0} Einträge', // single form only
			]);
		
		$result = (new Subject($this->getLocale()))
			->translatePlural($singular, $plural, 5, 5);
		Subject::setOverridesProvider(null);
		
		return $result === '5 Einträge';
	}
	
	public function degradesAnInvalidPatternToTheRawPhrase(): bool
	{
		$phrase = 'tests.translator.broken {0}';
		
		Subject::setOverridesProvider(
			static fn(Locale $locale): array => [$phrase => 'kaputt {0,']);
		
		$result = (new Subject($this->getLocale()))->translate($phrase, 'x');
		Subject::setOverridesProvider(null);
		
		return $result === 'kaputt {0,';
	}
}
