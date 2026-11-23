<?php
declare(strict_types=1);

namespace Ovos\Translator;

use Ovos\Translator\Catalog\Exception;
use Ovos\Translator\Catalog\Problem;
use Ovos\Translator\Po\Entry;

use function basename;
use function count;
use function file_get_contents;
use function in_array;
use function is_file;
use function ksort;
use function preg_replace;
use function sprintf;

use const SORT_STRING;

/**
 * Catalog - a .po and its .mo against the code: update() brings them in line,
 * check() says what is out of line
 *
 * The .po's header tells where the code is and which calls translate, as for
 * Poedit's "Update from code". A .mo is compared with its .po by content,
 * never by time: a checkout gives every file the time of the checkout.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Catalog
{
	protected MoWriter $writer;
	
	public function __construct()
	{
		$this->writer = new MoWriter;
	}
	
	/**
	 * The .mo beside a .po, of the same name
	 */
	public static function getMoPath(
		string $poPath,
	): string
	{
		return preg_replace('/\.po$/i', '', $poPath) . '.mo';
	}
	
	/**
	 * Merges the code's msgids into the .po and writes the .po and the .mo
	 * that differ from what they should be. The dates Poedit sets on an update
	 * are set when an entry changed.
	 *
	 * @return array{added: Entry[], obsolete: Entry[], revived: Entry[], written: string[]}
	 * @throws Exception when the .po or its sources are refused; nothing is
	 *     written then
	 */
	public function update(
		string $poPath,
	): array
	{
		$content = is_file($poPath)
			? file_get_contents($poPath)
			: false;
		if($content === false)
		{
			throw new Exception(sprintf('Cannot read "%s".', $poPath));
		}
		
		$po = Po::fromString($content, $poPath);
		$before = $po->toString();
		
		$changes = $po->merge($this->getExtractor($po, $poPath)->extract());
		if($po->toString() !== $before)
		{
			$po->touch();
		}
		
		$changes['written'] = [];
		if($po->toString() !== $content)
		{
			$po->save($poPath);
			$changes['written'][] = $poPath;
		}
		
		$moPath = self::getMoPath($poPath);
		if($this->readMo($moPath) !== $this->writer->getTranslations($po))
		{
			$this->writer->write($po, $moPath);
			$changes['written'][] = $moPath;
		}
		
		return $changes;
	}
	
	/**
	 * What is out of line, the code's msgids first in the code's order: one
	 * the .po misses, an empty or fuzzy translation, a wrong number of plural
	 * forms, then the .po's msgids the code does not use, then the .mo. A
	 * refused .po or sources are the one problem of the file.
	 *
	 * @return Problem[]
	 */
	public function check(
		string $poPath,
	): array
	{
		try
		{
			return $this->findProblems($poPath);
		}
		catch(Exception $exception)
		{
			return [new Problem($poPath, Problem::REFUSED, detail: $exception->getMessage())];
		}
	}
	
	/**
	 * @return Problem[]
	 * @throws Exception
	 */
	protected function findProblems(
		string $poPath,
	): array
	{
		$po = Po::fromFile($poPath);
		$forms = $po->getPluralCount();
		
		$messages = [];
		foreach($po->getMessages() as $entry)
		{
			$messages[$entry->getMoKey()] ??= $entry;
		}
		
		// keyed with the plural, so a changed plural misses its translation
		$used = [];
		$problems = [];
		foreach($this->getExtractor($po, $poPath)->extract() as $code)
		{
			$key = $code->getMoKey();
			$used[$key] = true;
			$entry = $messages[$key] ?? null;
			
			if($entry === null)
			{
				$problems[] = new Problem($poPath, Problem::MISSING, $code->msgid, $code->context);
			}
			else if(in_array('', $entry->translations, true))
			{
				$problems[] = new Problem($poPath, Problem::UNTRANSLATED, $entry->msgid, $entry->context);
			}
			else if($entry->isFuzzy())
			{
				$problems[] = new Problem($poPath, Problem::FUZZY, $entry->msgid, $entry->context);
			}
			
			if($entry?->plural !== null
				&& count($entry->translations) !== $forms)
			{
				$problems[] = new Problem(
					$poPath,
					Problem::FORMS,
					$entry->msgid,
					$entry->context,
					sprintf('%d forms, nplurals=%d', count($entry->translations), $forms),
				);
			}
		}
		
		foreach($messages as $key => $entry)
		{
			if(isset($used[$key]) === false)
			{
				$problems[] = new Problem($poPath, Problem::STALE, $entry->msgid, $entry->context);
			}
		}
		
		$moPath = self::getMoPath($poPath);
		$mo = $this->readMo($moPath);
		if($mo === null)
		{
			$problems[] = new Problem($moPath, Problem::MO, detail: 'missing');
		}
		else if($mo !== $this->writer->getTranslations($po))
		{
			$problems[] = new Problem($moPath, Problem::MO, detail: 'not compiled from ' . basename($poPath));
		}
		
		return $problems;
	}
	
	protected function getExtractor(
		Po $po,
		string $poPath,
	): Extractor
	{
		return Extractor::fromPo($po, $poPath);
	}
	
	/**
	 * A .mo's translations sorted as MoWriter sorts them, none from a broken
	 * file, null without a file
	 *
	 * @return ?array<string, string>
	 */
	protected function readMo(
		string $moPath,
	): ?array
	{
		if(is_file($moPath) === false)
		{
			return null;
		}
		
		$translations = (new MoParser($moPath))->parse();
		ksort($translations, SORT_STRING);
		
		return $translations;
	}
}
