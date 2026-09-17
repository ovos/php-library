<?php
declare(strict_types=1);

namespace Ovos\Cache\Versioned;

use function array_key_first;
use function count;
use function explode;
use function is_array;
use function is_bool;
use function is_scalar;
use function is_string;

/**
 * Rules
 *
 * The invalidation rules one process knows about, kept in the shape a read
 * needs rather than the shape the stream has.
 *
 * A rule invalidates an item when it is newer than the item's watermark and
 * names one of the item's tags ('any'), or all of them ('all'; an 'all' rule
 * with no tags is a clear and matches every item). For the 'any' rules only
 * the newest rule per tag can decide - if it is not newer than the watermark,
 * no older rule for that tag is either - so that is all that is kept, and a
 * read costs the item's tag count whatever the length of the log. The 'all'
 * rules cannot be compacted per tag and stay a list, bounded by the retention
 * and by clears.
 *
 * The set follows the server's stream incrementally. Each refresh asks for
 * the entries from the last id it holds, inclusively. If that id comes back
 * first, everything after it is new. If it does not, the server no longer
 * holds it - the stream was trimmed past it, cleared to a newer rule, or
 * deleted - and what came back is the whole current stream, which replaces
 * what was held. Either way one round trip, sized by what changed.
 *
 * It also notices when the stream has lost rules. Every entry says whether
 * it opened the stream (cache_versioned_invalidate records it). An item
 * stamped with a real id has seen the stream hold at least that entry, so if
 * nothing is held now, or the oldest entry held opened the stream and is
 * newer than the stamp, whatever stood between is gone - evicted, deleted,
 * or lost with a slot - and may have invalidated the item. The item is stale
 * then, whatever its tags: the one verdict that cannot serve a value past
 * its invalidation. An item stamped 0-0 saw no rule and is exempt.
 *
 * toArray() / fromArray() carry the set across requests (see SharedRules);
 * the shape is plain arrays only, so APCu stores it as it is.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Rules
{
	/**
	 * The id of a stream that has no entries yet. Every real id sorts above
	 * it, so an item stamped with it has seen nothing.
	 */
	public const string NONE = '0-0';
	
	// Fields, as cache_versioned_invalidate writes them
	public const string FIELD_MODE = 'mode';
	public const string FIELD_TAGS = 'tags';
	public const string FIELD_FIRST = 'first';
	
	// Modes
	public const string MODE_ALL = 'all';
	
	// Array shape (toArray / fromArray)
	protected const string KEY_TAGS = 'tags';
	protected const string KEY_ALL = 'all';
	protected const string KEY_LAST = 'last';
	protected const string KEY_FIRST = 'first';
	protected const string KEY_FIRST_OPENED = 'first_opened';
	
	/**
	 * The newest 'any' rule id naming each tag: tag => [ms, sequence]
	 */
	protected array $tags = [];
	
	/**
	 * The 'all' rules, oldest first: [ms, sequence, tags[]]
	 */
	protected array $all = [];
	
	/**
	 * The newest id received from the server: where the next fetch starts,
	 * and the watermark a write may carry
	 */
	protected string $last = self::NONE;
	
	/**
	 * The oldest id received from the server, and whether that entry opened
	 * the stream - null when it did not say, having been written before the
	 * library recorded it
	 */
	protected string $first = self::NONE;
	
	protected ?bool $firstOpened = null;
	
	/**
	 * @param int $retentionMs how long a rule can matter, being the longest an item may live
	 */
	public function __construct(
		protected int $retentionMs,
	)
	{
	}
	
	public function last(): string
	{
		return $this->last;
	}
	
	public function isEmpty(): bool
	{
		return $this->last === self::NONE;
	}
	
	/**
	 * Merges the entries of an XRANGE that started at last(), inclusively -
	 * or at the beginning, when nothing was held yet
	 *
	 * @param array $entries id => fields, oldest first, as the client hands them back
	 */
	public function absorb(
		array $entries,
	): static
	{
		if($this->last !== self::NONE)
		{
			$start = array_key_first($entries);
			
			if($start !== null && (string)$start === $this->last)
			{
				unset($entries[$start]);
			}
			else
			{
				// the stream moved from under us: whatever it holds now is
				// the whole truth, and what we held is not part of it
				$this->tags = [];
				$this->all = [];
				$this->last = self::NONE;
				$this->first = self::NONE;
				$this->firstOpened = null;
			}
		}
		
		foreach($entries as $id => $fields)
		{
			$this->add((string)$id, $fields);
		}
		
		$this->trim();
		
		return $this;
	}
	
	/**
	 * Has any rule the item has not seen invalidated it?
	 *
	 * @param string[] $itemTags
	 */
	public function isStale(
		array $itemTags,
		string $mark,
	): bool
	{
		if($this->lostSince($mark))
		{
			return true;
		}
		
		[$markMs, $markSequence] = static::split($mark);
		
		$tags = [];
		foreach($itemTags as $tag)
		{
			$tags[$tag] = true;
		}
		
		foreach($tags as $tag => $_)
		{
			if(isset($this->tags[$tag])
				&& static::isNewer($this->tags[$tag][0], $this->tags[$tag][1], $markMs, $markSequence))
			{
				return true;
			}
		}
		
		foreach($this->all as [$ms, $sequence, $ruleTags])
		{
			// only the rules the item has not seen can invalidate it
			if(static::isNewer($ms, $sequence, $markMs, $markSequence) === false)
			{
				continue;
			}
			
			// a clear, or every tag of the rule on the item
			$matched = true;
			foreach($ruleTags as $tag)
			{
				if(isset($tags[$tag]) === false)
				{
					$matched = false;
					
					break;
				}
			}
			
			if($matched)
			{
				return true;
			}
		}
		
		return false;
	}
	
	/**
	 * The shape that crosses requests: plain arrays, nothing else
	 */
	public function toArray(): array
	{
		return [
			static::KEY_TAGS => $this->tags,
			static::KEY_ALL => $this->all,
			static::KEY_LAST => $this->last,
			static::KEY_FIRST => $this->first,
			static::KEY_FIRST_OPENED => $this->firstOpened,
		];
	}
	
	/**
	 * Rebuilds a set from toArray(); null when the shape is not one we wrote
	 */
	public static function fromArray(
		mixed $state,
		int $retentionMs,
	): ?static
	{
		if(is_array($state) === false
			|| is_array($state[static::KEY_TAGS] ?? null) === false
			|| is_array($state[static::KEY_ALL] ?? null) === false
			|| is_string($state[static::KEY_LAST] ?? null) === false
			|| is_string($state[static::KEY_FIRST] ?? null) === false)
		{
			return null;
		}
		
		$firstOpened = $state[static::KEY_FIRST_OPENED] ?? null;
		
		$rules = new static($retentionMs);
		$rules->tags = $state[static::KEY_TAGS];
		$rules->all = $state[static::KEY_ALL];
		$rules->last = $state[static::KEY_LAST];
		$rules->first = $state[static::KEY_FIRST];
		$rules->firstOpened = is_bool($firstOpened)
			? $firstOpened
			: null;
		
		return $rules;
	}
	
	/**
	 * @return array{int, int}
	 */
	public static function split(
		string $id,
	): array
	{
		$parts = explode('-', $id, 2);
		
		return [(int)$parts[0], (int)($parts[1] ?? 0)];
	}
	
	/**
	 * Is the first id newer than the second?
	 */
	public static function isNewerId(
		string $id,
		string $than,
	): bool
	{
		[$ms, $sequence] = static::split($id);
		[$thanMs, $thanSequence] = static::split($than);
		
		return static::isNewer($ms, $sequence, $thanMs, $thanSequence);
	}
	
	/**
	 * Has the stream lost rules the item has seen? (see the class doc)
	 *
	 * The comparison is an ordering, so it cannot see a rebuild that opened
	 * on an id the item already carries: ids come from the server clock, and
	 * a stream lost and reborn inside the millisecond its items were stamped
	 * in starts again at that same id. An item stamped on the opening rule of
	 * a stream that still holds it is the common case and must stay fresh, so
	 * equality cannot be read as loss. Telling the two apart needs identity
	 * rather than order - a token on the opening rule, carried by the stamp.
	 */
	protected function lostSince(
		string $mark,
	): bool
	{
		if($mark === self::NONE)
		{
			return false;
		}
		
		if($this->last === self::NONE)
		{
			return true;
		}
		
		if($this->firstOpened !== true)
		{
			return false;
		}
		
		return static::isNewerId($this->first, $mark);
	}
	
	protected function add(
		string $id,
		mixed $fields,
	): void
	{
		[$ms, $sequence] = static::split($id);
		
		$fields = is_array($fields)
			? $fields
			: [];
		$mode = (string)($fields[static::FIELD_MODE] ?? '');
		$tags = (string)($fields[static::FIELD_TAGS] ?? '');
		$tags = $tags === ''
			? []
			: explode(',', $tags);
		
		if($mode === static::MODE_ALL)
		{
			$this->all[] = [$ms, $sequence, $tags];
		}
		else
		{
			// entries arrive oldest first, so the last write for a tag is the newest rule
			foreach($tags as $tag)
			{
				$this->tags[$tag] = [$ms, $sequence];
			}
		}
		
		if($this->first === self::NONE)
		{
			// the oldest entry: does it say it opened the stream?
			$opened = $fields[static::FIELD_FIRST] ?? null;
			
			$this->first = $id;
			$this->firstOpened = is_scalar($opened)
				? (string)$opened === '1'
				: null;
		}
		
		$this->last = $id;
	}
	
	/**
	 * Drops what can no longer decide anything: rules older than the longest
	 * possible item lifetime, having nothing left alive to match, and rules
	 * older than a clear, which says everything they could say
	 */
	protected function trim(): void
	{
		if($this->last === self::NONE)
		{
			return;
		}
		
		[$lastMs] = static::split($this->last);
		$floorMs = $lastMs - $this->retentionMs;
		$floorSequence = 0;
		
		foreach($this->all as [$ms, $sequence, $tags])
		{
			if(count($tags) === 0
				&& static::isNewer($ms, $sequence, $floorMs, $floorSequence))
			{
				// a clear: nothing older than it can still matter
				$floorMs = $ms;
				$floorSequence = $sequence;
			}
		}
		
		foreach($this->tags as $tag => [$ms, $sequence])
		{
			if(static::isNewer($ms, $sequence, $floorMs, $floorSequence) === false)
			{
				unset($this->tags[$tag]);
			}
		}
		
		$kept = [];
		foreach($this->all as $rule)
		{
			// the clear that set the floor stays: it is the one still matching
			if(($rule[0] === $floorMs && $rule[1] === $floorSequence)
				|| static::isNewer($rule[0], $rule[1], $floorMs, $floorSequence))
			{
				$kept[] = $rule;
			}
		}
		
		$this->all = $kept;
	}
	
	protected static function isNewer(
		int $ms,
		int $sequence,
		int $thanMs,
		int $thanSequence,
	): bool
	{
		return $ms > $thanMs
			|| ($ms === $thanMs && $sequence > $thanSequence);
	}
}
