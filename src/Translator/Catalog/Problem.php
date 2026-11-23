<?php
declare(strict_types=1);

namespace Ovos\Translator\Catalog;

use function json_encode;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Problem - one thing Catalog::check() found wrong with a .po or its .mo
 *
 * @author Marcin Gil <mg@ovos.at>
 */
readonly class Problem
{
	/**
	 * The code uses the msgid, the .po does not have it
	 */
	public const string MISSING = 'missing';
	
	/**
	 * A form of the translation is empty
	 */
	public const string UNTRANSLATED = 'untranslated';
	
	public const string FUZZY = 'fuzzy';
	
	/**
	 * A plural entry has another number of forms than the header's nplurals
	 */
	public const string FORMS = 'forms';
	
	/**
	 * The .po has the msgid, the code no longer uses it
	 */
	public const string STALE = 'stale';
	
	/**
	 * The .mo is missing or its translations are not the .po's
	 */
	public const string MO = 'mo';
	
	/**
	 * The .po or its sources cannot be read without losing something; the
	 * detail says why
	 */
	public const string REFUSED = 'refused';
	
	public function __construct(
		public string $file,
		public string $kind,
		public ?string $msgid = null,
		public ?string $context = null,
		public string $detail = '',
	)
	{
	}
	
	/**
	 * The kind, the detail and the msgid, without the file
	 */
	public function getDescription(): string
	{
		$text = $this->kind;
		if($this->detail !== '')
		{
			$text .= ' (' . $this->detail . ')';
		}
		
		if($this->context !== null)
		{
			$text .= ' ' . self::quote($this->context) . ' |';
		}
		
		return $this->msgid === null
			? $text
			: $text . ' ' . self::quote($this->msgid);
	}
	
	public function __toString(): string
	{
		return $this->file . ': ' . $this->getDescription();
	}
	
	protected static function quote(
		string $text,
	): string
	{
		return (string)json_encode($text, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}
}
