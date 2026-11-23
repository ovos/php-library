<?php
declare(strict_types=1);

namespace Ovos\Translator;

use Ovos\Translator;

/**
 * Translation
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Translation
{
	protected Translator $translator;
	
	protected string $path;
	
	protected ?CachedAdapter $adapter = null;
	
	public function __construct(
		Translator $translator,
		string $path,
	)
	{
		$this->setTranslator($translator);
		$this->setPath($path);
	}
	
	public function setPath(
		string $path,
	): static
	{
		$this->path = $path;
		
		return $this;
	}
	
	public function getPath(): string
	{
		return $this->path;
	}
	
	public function setTranslator(
		Translator $translator,
	): static
	{
		$this->translator = $translator;
		
		return $this;
	}
	
	public function getTranslator(): Translator
	{
		return $this->translator;
	}
	
	public function translate(
		string $phrase,
	): string
	{
		return $this->getAdapter()
			->gettext($phrase);
	}
	
	public function translatePlural(
		string $phraseSingular,
		string $phrasePlural,
		int $n,
	): string
	{
		return $this->getAdapter()
			->ngettext(
				$phraseSingular,
				$phrasePlural,
				$n,
			);
	}
	
	public function translateContext(
		string $context,
		string $phrase,
	): string
	{
		return $this->getAdapter()
			->pgettext($context, $phrase);
	}
	
	public function translatePluralContext(
		string $context,
		string $phraseSingular,
		string $phrasePlural,
		int $n,
	): string
	{
		return $this->getAdapter()
			->npgettext(
				$context,
				$phraseSingular,
				$phrasePlural,
				$n,
			);
	}
	
	/**
	 * Whether this file holds the raw gettext key, whatever its translation
	 */
	public function exists(
		string $messageId,
	): bool
	{
		return $this->getAdapter()
			->exists($messageId);
	}
	
	public function getAdapter(): CachedAdapter
	{
		if($this->adapter === null)
		{
			$this->adapter = new CachedAdapter($this);
		}
		
		return $this->adapter;
	}
	
	public function __toString(): string
	{
		return $this->path;
	}
}
