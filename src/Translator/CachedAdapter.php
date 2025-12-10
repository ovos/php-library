<?php
declare(strict_types=1);

namespace Ovos\Translator;

use Ovos\ArrayObject;
use Ovos\Container;
use Ovos\Service\Cache;

use function Ovos\container;
use function array_key_exists;
use function explode;
use function file_exists;
use function filemtime;
use function implode;
use function str_contains;
use function strlen;
use function substr;

/**
 * CachedTranslator
 *
 * @package Ovos
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
			->getPerishableStore();
		
		$mTime = filemtime($filename);
		$path = substr($filename, strlen(BASE_DIR));
		$cacheId = $store->pathToId($path); // a static method accessed from the instance
		
		if(($item = $store->get($cacheId))
			&& $item->mtime === $mTime)
		{
			$this->setTranslations($item->translations->getArrayCopy());
		}
		
		$parser = new MoParser($filename);
		$this->setTranslations($parser->getTranslations());
		
		$item = new ArrayObject;
		$item->mtime = $mTime;
		$item->translations = $this->getTranslations();
		
		$store->set($cacheId, $item);
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
		// this should contain all strings separated by NULLs
		$key = implode(chr(0), [$messageId, $messageIdPlural]);
		if($this->exists($key))
		{
			return $number !== 1 ? $messageIdPlural : $messageId;
		}
		
		$result = $this->gettext($key);
		
		// find out the appropriate form
		$select = $this->translation->getTranslator()
			->getPlural($number);
		
		$list = explode(chr(0), $result);
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
