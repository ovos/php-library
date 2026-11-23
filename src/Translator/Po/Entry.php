<?php
declare(strict_types=1);

namespace Ovos\Translator\Po;

use function array_filter;
use function array_unshift;
use function array_values;
use function chr;
use function in_array;

/**
 * Entry - one message of a .po file: comments, flags, msgids and translations
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Entry
{
	public const string FLAG_FUZZY = 'fuzzy';
	
	/**
	 * Translator comments, the "#" lines
	 *
	 * @var string[]
	 */
	public array $comments = [];
	
	/**
	 * Comments for the translator taken from the code, the "#." lines
	 *
	 * @var string[]
	 */
	public array $extractedComments = [];
	
	/**
	 * Where the code uses the msgid, "path:line" each, the "#:" lines
	 *
	 * @var string[]
	 */
	public array $references = [];
	
	/**
	 * The "#," flags, such as fuzzy or php-format
	 *
	 * @var string[]
	 */
	public array $flags = [];
	
	/**
	 * The msgids a fuzzy translation was made for, the "#|" lines
	 */
	public ?string $previousContext = null;
	
	public ?string $previousMsgid = null;
	
	public ?string $previousPlural = null;
	
	public ?string $context = null;
	
	public string $msgid = '';
	
	public ?string $plural = null;
	
	/**
	 * The msgstr, or msgstr[0], msgstr[1] ... of a plural entry
	 *
	 * @var string[]
	 */
	public array $translations = [''];
	
	/**
	 * A "#~" entry: kept for its translation, not used by the code
	 */
	public bool $obsolete = false;
	
	public function __construct(
		string $msgid = '',
		?string $plural = null,
		?string $context = null,
	)
	{
		$this->msgid = $msgid;
		$this->plural = $plural;
		$this->context = $context;
	}
	
	/**
	 * What makes an entry unique in a .po file: its context and msgid
	 */
	public function getKey(): string
	{
		return $this->context === null
			? $this->msgid
			: $this->context . chr(4) . $this->msgid;
	}
	
	/**
	 * The entry's key in a .mo file, as MoParser reads it
	 */
	public function getMoKey(): string
	{
		return $this->plural === null
			? $this->getKey()
			: $this->getKey() . chr(0) . $this->plural;
	}
	
	public function isHeader(): bool
	{
		return $this->msgid === ''
			&& $this->context === null;
	}
	
	public function isFuzzy(): bool
	{
		return in_array(self::FLAG_FUZZY, $this->flags, true);
	}
	
	public function setFuzzy(
		bool $fuzzy,
	): static
	{
		$this->flags = array_values(array_filter(
			$this->flags,
			static fn(string $flag): bool => $flag !== self::FLAG_FUZZY,
		));
		
		if($fuzzy)
		{
			array_unshift($this->flags, self::FLAG_FUZZY);
		}
		
		return $this;
	}
	
	/**
	 * msgfmt takes an entry only when its first form is translated
	 */
	public function isTranslated(): bool
	{
		return ($this->translations[0] ?? '') !== '';
	}
}
