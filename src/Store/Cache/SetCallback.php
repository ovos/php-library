<?php
declare(strict_types=1);

namespace Ovos\Store\Cache;

use Closure;

/**
 * SetCallback
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class SetCallback
{
	/**
	 * @var ?Closure
	 */
	protected ?Closure $_callback;
	
	/**
	 * @var int
	 */
	protected int $_ttl = 0;
	
	/**
	 * @var array
	 */
	protected array $_tags = [];
	
	/**
	 * @param ?callable $callback
	 * @param int $ttl
	 * @param array $tags
	 */
	public function __construct(
		?callable $callback = null,
		int $ttl = 0,
		array $tags = [],
	)
	{
		$this->setCallback($callback);
		$this->setTtl($ttl);
		$this->setTags($tags);
	}
	
	/**
	 * @param ?callable $callback
	 *
	 * @return self
	 */
	public function setCallback(?callable $callback = null): self
	{
		$this->_callback = $callback;
		
		return $this;
	}
	
	public function getCallback(): ?Closure
	{
		return $this->_callback;
	}
	
	/**
	 * @param int $ttl
	 *
	 * @return self
	 */
	public function setTtl(int $ttl = 0): self
	{
		$this->_ttl = $ttl;
		
		return $this;
	}
	
	public function getTtl(): int
	{
		return $this->_ttl;
	}
	
	/**
	 * @param array $tags
	 *
	 * @return self
	 */
	public function setTags(array $tags = []): self
	{
		$this->_tags = $tags;
		
		return $this;
	}
	
	public function getTags(): array
	{
		return $this->_tags;
	}
}
