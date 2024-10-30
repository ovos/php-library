<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Test\Internal;
use Throwable;

/**
 * Test
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Test
{
	/**#@+
	 * Result constants
	 */
	public const RESULT_FAILED = 0;
	public const RESULT_PASSED = 1;
	public const RESULT_SKIPPED = 2;
	/**#@-*/
	
	/**
	 * @var int
	 */
	public int $result = self::RESULT_FAILED;
	
	/**
	 * @var ?Throwable
	 */
	public ?Throwable $throwable = null;
	
	/**
	 * @var ?string
	 */
	public ?string $reason = null;
	
	/**
	 * @var bool
	 */
	protected bool $_isDisabled = false;
	
	/**
	 * @param ?string $reason
	 *
	 * @return self
	 */
	#[Internal] 
	public function setReason(?string $reason): self
	{
		$this->reason = $reason;
		
		return $this;
	}
	
	/**
	 * @param bool $isDisabled
	 *
	 * @return self
	 */
	#[Internal]
	public function setIsDisabled(bool $isDisabled): self
	{
		$this->_isDisabled = $isDisabled;
		
		return $this;
	}
	
	/**
	 * @return bool
	 */
	#[Internal]
	public function isDisabled(): bool
	{
		return $this->_isDisabled;
	}
}
