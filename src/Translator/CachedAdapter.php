<?php
declare(strict_types=1);

namespace Ovos\Translator;

use Ovos\ArrayObject;
use Ovos\Cache\Key\Normalizer;
use Ovos\Container;
use Ovos\Service\Cache;

use function Ovos\container;

use function array_key_exists;
use function chr;
use function explode;
use function file_exists;
use function filemtime;
use function implode;
use function str_contains;
use function strlen;
use function substr;

use const BASE_DIR;

/**
 * CachedTranslator
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class CachedAdapter
{
	protected Container $container;
	
	protected Translation $translation;
	
	protected array $translations = [];
	
	public function __construct(Translation $translation)
	{
		$this->container = container();
		$this->translation = $translation;
		
		$filename = $translation->getPath();
		if(file_exists($filename) === false)
		{
			return;
		}
		
		$store = $this->container->get(Cache::SYMBOL)
			->getPerishable()->getStore();
		
		$mTime = filemtime($filename);
		$cacheId = self::getCacheId($filename);
		
		if(($item = $store->get($cacheId))
			&& $item->mtime === $mTime)
		{
			$this->setTranslations($item->translations->getArrayCopy());
			
			// the missing return here once made this cache write-only: every
			// request re-parsed the .mo and re-set the entry it had just read
			return;
		}
		
		$parser = new MoParser($filename);
		$this->setTranslations($parser->getTranslations());
		
		$item = new ArrayObject;
		$item->mtime = $mTime;
		$item->translations = $this->getTranslations();
		
		$store->set($cacheId, $item);
	}
	
	/**
	 * The perishable store's key for a .mo file's parsed map: its path below
	 * BASE_DIR. The map is reused while the file's mtime is unchanged
	 */
	public static function getCacheId(
		string $filename,
	): string
	{
		return Normalizer::fromPath(substr($filename, strlen(BASE_DIR)));
	}
	
	/**
	 * Translates a string
	 */
	public function gettext(
		string $messageId, // string to be translated
	): string // translated string (or original, if not found)
	{
		return $this->exists($messageId)
			? $this->translations[$messageId] : $messageId;
	}
	
	/**
	 * Check if a string is translated
	 */
	public function exists(
		string $messageId, // string to be checked
	): bool
	{
		return array_key_exists($messageId, $this->translations);
	}
	
	/**
	 * Translate with context
	 */
	public function pgettext(
		string $messageContext, // context
		string $messageId, // string to be translated
	): string // translated plural form
	{
		$key = implode(chr(4), [$messageContext, $messageId]);
		$translated = $this->gettext($key);
		if(str_contains($translated, chr(4)))
		{
			return $messageId;
		}
		
		return $translated;
	}
	
	/**
	 * Plural version of gettext
	 */
	public function ngettext(
		string $messageId, // single form
		string $messageIdPlural, // plural form
		int $number, // number of objects
	): string // translated plural form
	{
		// a plural entry is keyed by both msgids, NUL-joined, as in the .mo
		$key = implode(chr(0), [$messageId, $messageIdPlural]);
		if($this->exists($key) === false)
		{
			// not in this file: the English text, by the English rule
			return $number === 1 ? $messageId : $messageIdPlural;
		}
		
		// the forms are NUL-joined; the language's rule picks one
		$select = $this->translation->getTranslator()
			->getPlural($number);
		
		$list = explode(chr(0), $this->translations[$key]);
		if(isset($list[$select]) === false)
		{
			return $list[0];
		}
		
		return $list[$select];
	}
	
	/**
	 * Plural version of pgettext.
	 */
	public function npgettext(
		string $messageContext, // context
		string $messageId, // single form
		string $messageIdPlural, // plural form
		int $number, // number of objects
	): string // translated plural form
	{
		$key = implode(chr(4), [$messageContext, $messageId]);
		$translated = $this->ngettext($key, $messageIdPlural, $number);
		// a missing entry comes back as the singular key for a count of 1
		if(str_contains($translated, chr(4)))
		{
			return $messageId;
		}
		
		return $translated;
	}
	
	public function setTranslations(
		array $translations,
	): static
	{
		$this->translations = $translations;
		
		return $this;
	}
	
	public function getTranslations(): array
	{
		return $this->translations;
	}
}
