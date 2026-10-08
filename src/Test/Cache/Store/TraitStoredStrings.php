<?php
declare(strict_types=1);

namespace Ovos\Test\Cache\Store;

use Ovos\Cache\Compressor;
use Ovos\Cache\Serializer;
use Ovos\Cache\Stale;
use Ovos\Cache\Store\KeyValue;
use stdClass;

use function gzcompress;
use function serialize;

use const INF;

/**
 * TraitStoredStrings
 *
 * The rule every store shares about the strings it keeps: a string comes
 * back as the string it was, whatever it looks like. The serializer and the
 * compressor mark what they produce in-band, so a string shaped like their
 * output - serialized objects, a compressed payload, a stale record - must
 * be escaped on the write, or the read turns a string an outsider wrote
 * into objects of their choosing
 *
 * @author Marcin Gil <mg@ovos.at>
 */
trait TraitStoredStrings
{
	/**
	 * A fresh instance of the store under test - another process
	 */
	abstract protected function staleStore(): KeyValue;
	
	/**
	 * RULE: a string that starts like serialized data reads back as itself
	 */
	public function aStringThatLooksSerializedReadsBackAsItself(): bool
	{
		return $this->readsBackAsItself('looks-serialized',
			Serializer::PREFIX_SERIALIZE . serialize(new stdClass),
		);
	}
	
	/**
	 * RULE: a short string that carries the compressor's marker reads back as
	 * itself, not as what its payload decompresses to
	 */
	public function aStringThatLooksCompressedReadsBackAsItself(): bool
	{
		return $this->readsBackAsItself('looks-compressed',
			'gz' . Compressor::PREFIX_COMPRESS . gzcompress(Serializer::PREFIX_SERIALIZE . serialize(new stdClass)),
		);
	}
	
	/**
	 * RULE: a string shaped like a stored stale record reads back as itself,
	 * not as the value the record would carry
	 */
	public function aStringThatLooksLikeAStaleRecordReadsBackAsItself(): bool
	{
		return $this->readsBackAsItself('looks-stale',
			Serializer::PREFIX_SERIALIZE . serialize(new Stale('carried', INF)),
		);
	}
	
	protected function readsBackAsItself(
		string $name,
		string $value,
	): bool
	{
		$key = 'stored-strings-' . $name;
		$store = $this->staleStore();
		$store->delete($key);
		$store->set($key, $value, 60);
		
		$read = $this->staleStore()
			->get($key, queue: false);
		$store->delete($key);
		
		return $read === $value;
	}
}
