<?php
declare(strict_types=1);

namespace Ovos\Cache\Store;

use Ovos\ArrayObject;
use Ovos\Cache\Compressor;
use Ovos\Invoker;
use Ovos\Cache\MemoLock;
use Ovos\Cache\Prefixer;
use Ovos\Cache\Serializer;
use Closure;

/**
 * KeyValue
 *
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class KeyValue
{
	protected Invoker $invoker;
	protected Prefixer $prefixer;
	protected Serializer $serializer;
	protected Compressor $compressor;
	protected ?MemoLock $memoLock = null;
	
	// Groups
	public const string GROUP_DEFAULT = 'core';
	public const string GROUP_TESTS = 'tests';
	public const string GROUP_BENCHMARKS = 'benchmarks';
	
	protected ?string $group = self::GROUP_DEFAULT;
	
	protected ?ArrayObject $config = null;
	
	public function __construct(
		?string $prefix = null,
		?ArrayObject $config = null,
		?string $group = null,
	)
	{
		$this->config = $config;
		
		$this->invoker = new Invoker($this);
		$this->prefixer = new Prefixer(
			$prefix,
		);
		
		$this->serializer = new Serializer;
		$this->compressor = new Compressor(
			$config,
		);
		
		$this->setGroup($group);
	}
	
	public function setGroup(
		?string $group,
	): static
	{
		$this->group = $group;
		
		return $this;
	}
	
	public function getGroup(): ?string
	{
		if($this->group !== null)
		{
			return $this->prefixer
				->prefix($this->group);
		}
		
		return null;
	}
	
	public function getPrefixer(): Prefixer
	{
		return $this->prefixer;
	}
	
	public function prefix(
		string $key,
		?string $prefix = null,
		string $separator = Prefixer::SEPARATOR_PREFIX,
	): string
	{
		return $this->prefixer
			->prefix($key, $prefix, $separator);
	}
	
	abstract public function getMemoLock(): MemoLock;
	
	public function setQueueEnabled(
		bool $enabled,
	): static
	{
		$this->getMemoLock()
			->setQueueEnabled($enabled);
		
		return $this;
	}
	
	public function isQueueEnabled(): bool
	{
		return $this->getMemoLock()
			->isQueueEnabled();
	}
	
	abstract public function lockAndQueue(
		string $key,
	): mixed;
	
	abstract public function releaseActiveLock(
		string $key,
	): bool;
	
	abstract public function renewLock(
		string $key,
	): bool;
	
	abstract public function get(
		string $key,
		?Closure $resolver = null,
		int $ttl = 0,
	): mixed;
	
	public function setFromResolver(
		string $key,
		?Closure $resolver,
		int $ttl = 0,
	): mixed
	{
		$value = $this->invoker
			->invoke($resolver);
		
		if($value !== null)
		{
			$this->set($key, $value, $ttl);
		}
		
		return $value;
	}
	
	abstract public function set(
		string $key,
		mixed $value,
		int $ttl = 0,
	): bool;
}
