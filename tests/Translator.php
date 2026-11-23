<?php
declare(strict_types=1);

namespace Tests;

use Ovos\Locale;
use Ovos\Service\Cache;
use Ovos\Test;
use Ovos\Test\Internal;
use Ovos\Translator as Subject;
use Ovos\Translator\CachedAdapter;
use Ovos\Translator\Translation;
use Override;

use function chr;

/**
 * Translator - the overrides provider layered over the .mo translations,
 * and a compiled Polish .mo read through every lookup
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Translator extends Test
{
	/**
	 * Compiled from pl.po beside it with GNU msgfmt
	 */
	protected const string MO = __DIR__ . '/Translator/files/pl.mo';
	
	/**
	 * Called by the runner before each test method. The perishable store
	 * keeps a parsed .mo while the file's mtime is unchanged: dropping it
	 * makes every test parse the fixture as it is on disk
	 */
	#[Internal]
	#[Override]
	public function prepare(): void
	{
		Subject::setOverridesProvider(null);
		Subject::setMissingHandler(null);
		
		$this->container->get(Cache::SYMBOL)
			->getPerishable()
			->getStore()
			->delete(CachedAdapter::getCacheId(self::MO));
	}
	
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
	
	/**
	 * A Polish translator holding only the fixture, so no translation path of
	 * the application running the tests takes part
	 */
	protected function getPolish(
		?Translation $translation = null,
		?Translation $before = null,
	): Subject
	{
		$locale = (new Locale('pl'))
			->setSymbol('pl_PL')
			->setLanguage('pl');
		
		return new class($locale, self::MO, $translation, $before) extends Subject
		{
			public function __construct(
				Locale $locale,
				string $path,
				?Translation $translation,
				?Translation $before,
			)
			{
				parent::__construct($locale);
				
				$this->translations = [];
				if($before !== null)
				{
					$this->translations[$before->getPath()] = $before->setTranslator($this);
				}
				
				$translation ??= new Translation($this, $path);
				$this->translations[$path] = $translation->setTranslator($this);
			}
		};
	}
	
	/**
	 * A translation path before the fixture that translates every msgid
	 * to text of its own
	 */
	protected function getEarlierPath(): Translation
	{
		return new class(new Subject($this->getLocale()), 'tests.translator.earlier.mo') extends Translation
		{
			#[Override]
			public function exists(
				string $messageId,
			): bool
			{
				return true;
			}
			
			#[Override]
			public function translate(
				string $phrase,
			): string
			{
				return 'Okej';
			}
			
			#[Override]
			public function translatePlural(
				string $phraseSingular,
				string $phrasePlural,
				int $n,
			): string
			{
				return 'Statusy';
			}
		};
	}
	
	/**
	 * The fixture's Translation, counting the raw-key lookups that has() and
	 * the missing handler make
	 */
	protected function getCountingTranslation(): Translation
	{
		// getPolish() hands it the Polish translator
		return new class(new Subject($this->getLocale()), self::MO) extends Translation
		{
			public int $lookups = 0;
			
			#[Override]
			public function exists(
				string $messageId,
			): bool
			{
				$this->lookups++;
				
				return parent::exists($messageId);
			}
		};
	}
	
	protected function getAdapter(
		Subject $subject,
	): CachedAdapter
	{
		return $subject->getTranslations()[self::MO]
			->getAdapter();
	}
	
	public function picksEveryPolishPluralFormFromTheFile(): bool
	{
		$subject = $this->getPolish();
		
		$forms = [];
		foreach([1, 2, 5, 12, 22, 0] as $number)
		{
			$forms[] = $subject->translatePlural('{0} file', '{0} files', $number);
		}
		
		return $forms === [
			'{0} plik',
			'{0} pliki',
			'{0} plików',
			'{0} plików',
			'{0} pliki',
			'{0} plików',
		];
	}
	
	public function fallsBackToEnglishForAMissingPlural(): bool
	{
		$subject = $this->getPolish();
		$adapter = $this->getAdapter($subject);
		$singular = 'tests.translator.{0} cat';
		$plural = 'tests.translator.{0} cats';
		
		return $adapter->ngettext($singular, $plural, 1) === $singular
			&& $adapter->ngettext($singular, $plural, 2) === $plural
			&& $adapter->ngettext($singular, $plural, 5) === $plural
			&& $subject->translatePlural($singular, $plural, 1) === $singular
			&& $subject->translatePlural($singular, $plural, 2) === $plural;
	}
	
	public function translatesWithContext(): bool
	{
		$subject = $this->getPolish();
		
		// the plain entry and the context entry share the msgid
		return $subject->translateContext('verb', 'Open') === 'Otwórz'
			&& $subject->translate('Open') === 'Otwarte'
			&& $subject->translateContext('noun', 'Open') === 'Open';
	}
	
	public function translatesAPluralWithContext(): bool
	{
		$subject = $this->getPolish();
		$singular = '{0} new message';
		$plural = '{0} new messages';
		
		return $subject->translatePluralContext('inbox', $singular, $plural, 1, 1) === '1 nowa wiadomość'
			&& $subject->translatePluralContext('inbox', $singular, $plural, 3, 3) === '3 nowe wiadomości'
			&& $subject->translatePluralContext('inbox', $singular, $plural, 5, 5) === '5 nowych wiadomości';
	}
	
	public function aMissingContextFallsBackToEnglish(): bool
	{
		$subject = $this->getPolish();
		$adapter = $this->getAdapter($subject);
		$singular = '{0} new message';
		$plural = '{0} new messages';
		
		// the msgids are in the file, under another context only
		return $adapter->npgettext('archive', $singular, $plural, 1) === $singular
			&& $adapter->npgettext('archive', $singular, $plural, 2) === $plural
			&& $subject->translatePluralContext('archive', $singular, $plural, 1) === $singular
			&& $subject->translatePluralContext('archive', $singular, $plural, 2) === $plural;
	}
	
	public function hasAnEntryTranslatedToTheSameText(): bool
	{
		$subject = $this->getPolish();
		
		// translate() gives the msgid back whether the entry is there or not
		return $subject->translate('OK') === 'OK'
			&& $subject->has('OK')
			&& $subject->has('tests.translator.not in the file') === false;
	}
	
	public function hasKeysPluralsAndContextsAsTheFile(): bool
	{
		$subject = $this->getPolish();
		
		return $subject->has('{0} file', '{0} files')
			&& $subject->has('{0} file') === false
			&& $subject->has('Open', context: 'verb')
			&& $subject->has('Open', context: 'noun') === false
			&& $subject->has('{0} new message', '{0} new messages', 'inbox')
			&& $subject->has('{0} new message', '{0} new messages') === false;
	}
	
	public function hasAnOverride(): bool
	{
		$phrase = 'tests.translator.overridden only';
		$empty = 'tests.translator.empty override';
		
		Subject::setOverridesProvider(
			static fn(Locale $locale): array => [
				$phrase => 'nadpisane',
				$empty => '',
				'tests.translator.{0} dog' . chr(0) . 'tests.translator.{0} dogs' => '{0} pies',
			]);
		
		$subject = $this->getPolish();
		$result = $subject->has($phrase)
			&& $subject->has($empty) === false
			&& $subject->has('tests.translator.{0} dog', 'tests.translator.{0} dogs');
		Subject::setOverridesProvider(null);
		
		return $result;
	}
	
	public function callsTheMissingHandlerOnceForAMissingMsgid(): bool
	{
		$calls = [];
		Subject::setMissingHandler(
			static function(
				Locale $locale,
				string $msgid,
				?string $plural,
				?string $context,
			) use (&$calls): void
			{
				$calls[] = [$locale->getLanguage(), $msgid, $plural, $context];
			});
		
		$subject = $this->getPolish();
		$results = [
			$subject->translate('tests.translator.not in the file'),
			$subject->translatePlural('tests.translator.{0} cat', 'tests.translator.{0} cats', 2),
			$subject->translateContext('noun', 'Open'),
			$subject->translatePluralContext('archive', '{0} new message', '{0} new messages', 1),
		];
		Subject::setMissingHandler(null);
		
		// the English text comes back as it does without a handler
		$english = [
			'tests.translator.not in the file',
			'tests.translator.{0} cats',
			'Open',
			'{0} new message',
		];
		$missed = [
			['pl', 'tests.translator.not in the file', null, null],
			['pl', 'tests.translator.{0} cat', 'tests.translator.{0} cats', null],
			['pl', 'Open', null, 'noun'],
			['pl', '{0} new message', '{0} new messages', 'archive'],
		];
		
		return $results === $english
			&& $calls === $missed;
	}
	
	public function doesNotCallTheMissingHandlerForAPresentMsgid(): bool
	{
		$calls = 0;
		Subject::setMissingHandler(
			static function() use (&$calls): void
			{
				$calls++;
			});
		Subject::setOverridesProvider(
			static fn(Locale $locale): array => ['tests.translator.overridden only' => 'nadpisane']);
		
		$subject = $this->getPolish();
		// translated to its own text
		$subject->translate('OK');
		$subject->translate('Open');
		$subject->translatePlural('{0} file', '{0} files', 5);
		$subject->translateContext('verb', 'Open');
		$subject->translatePluralContext('inbox', '{0} new message', '{0} new messages', 5);
		$subject->translate('tests.translator.overridden only');
		Subject::setMissingHandler(null);
		Subject::setOverridesProvider(null);
		
		return $calls === 0;
	}
	
	public function withoutAMissingHandlerNothingChanges(): bool
	{
		$translation = $this->getCountingTranslation();
		$subject = $this->getPolish($translation);
		
		$results = [
			$subject->translate('tests.translator.not in the file'),
			$subject->translate('Open'),
			$subject->translatePlural('tests.translator.{0} cat', 'tests.translator.{0} cats', 2),
			$subject->translatePlural('{0} file', '{0} files', 5),
			$subject->translateContext('noun', 'Open'),
			$subject->translatePluralContext('archive', '{0} new message', '{0} new messages', 1),
		];
		$expected = [
			'tests.translator.not in the file',
			'Otwarte',
			'tests.translator.{0} cats',
			'{0} plików',
			'Open',
			'{0} new message',
		];
		
		// one raw-key lookup per path and phrase; has() shows the count works
		return Subject::getMissingHandler() === null
			&& $results === $expected
			&& $translation->lookups === 6
			&& $subject->has('OK')
			&& $translation->lookups === 7;
	}
	
	public function theMissingHandlerReusesTheLookup(): bool
	{
		Subject::setMissingHandler(
			static function(): void
			{
			});
		
		$translation = $this->getCountingTranslation();
		$subject = $this->getPolish($translation);
		$subject->translate('tests.translator.not in the file');
		$subject->translate('Open');
		$subject->translatePlural('tests.translator.{0} cat', 'tests.translator.{0} cats', 2);
		$subject->translatePlural('{0} file', '{0} files', 5);
		$subject->translateContext('noun', 'Open');
		$subject->translatePluralContext('archive', '{0} new message', '{0} new messages', 1);
		Subject::setMissingHandler(null);
		
		// the same one lookup per path and phrase as without a handler
		return $translation->lookups === 6;
	}
	
	public function keepsAPluralFormThatReadsLikeTheMsgid(): bool
	{
		$subject = $this->getPolish();
		
		return $subject->translatePlural('Status', 'Statuses', 1) === 'Status'
			&& $subject->translatePlural('Status', 'Statuses', 2) === 'Status'
			&& $subject->translatePlural('Status', 'Statuses', 5) === 'Status';
	}
	
	public function aLaterPathHoldingTheMsgidWinsWithTheSameText(): bool
	{
		$subject = $this->getPolish(before: $this->getEarlierPath());
		
		return $subject->translate('OK') === 'OK'
			&& $subject->translatePlural('Status', 'Statuses', 5) === 'Status'
			&& $subject->translate('Open') === 'Otwarte';
	}
	
	public function fallsBackByTheEnglishRuleWhateverTheLanguage(): bool
	{
		$japanese = (new Locale('ja'))
			->setSymbol('ja_JP')
			->setLanguage('ja');
		$french = (new Locale('fr'))
			->setSymbol('fr_FR')
			->setLanguage('fr');
		$singular = 'tests.translator.{0} file';
		$plural = 'tests.translator.{0} files';
		
		$subject = new Subject($japanese);
		
		// Japanese has one form: getPlural() is 0 for every number
		return $subject->getPlural(5) === 0
			&& $subject->translatePlural($singular, $plural, 5) === $plural
			&& $subject->translatePlural($singular, $plural, 1) === $singular
			&& (new Subject($french))->translatePlural($singular, $plural, 0) === $plural;
	}
}
