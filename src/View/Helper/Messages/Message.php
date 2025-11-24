<?php
declare(strict_types=1);

namespace Ovos\View\Helper\Messages;

use Ovos\View;

/**
 * Message
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Message
{
	/**#@+
	 * Types
	 */
	public const string TYPE_INFO = 'info';
	public const string TYPE_SUCCESS = 'success';
	public const string TYPE_WARNING = 'warning';
	public const string TYPE_ERROR = 'error';
	/**#@-*/
	
	/**
	 * @var ?string
	 */
	protected ?string $_title;
	
	/**
	 * @var ?string
	 */
	protected ?string $_description;
	
	/**
	 * @var string
	 */
	protected string $_type;
	
	/**
	 * @var bool
	 */
	protected bool $_raw;
	
	/**
	 * @param string $type
	 * @param ?string $description
	 * @param ?string $title
	 * @param bool $raw
	 */
	public function __construct(
		string $type = self::TYPE_SUCCESS,
		?string $description = null,
		?string $title = null,
		bool $raw = false,
	)
	{
		$this->setType($type);
		$this->setDescription($description);
		$this->setTitle($title);
		$this->setIsRaw($raw);
	}
	
	/**
	 * @param ?string $title
	 *
	 * @return static
	 */
	public function setTitle(?string $title): static
	{
		$this->_title = $title;
		
		return $this;
	}
	
	/**
	 * @return ?string
	 */
	public function getTitle(): ?string
	{
		return $this->_title;
	}
	
	/**
	 * @param ?string $description
	 *
	 * @return static
	 */
	public function setDescription(?string $description): static
	{
		$this->_description = $description;
		
		return $this;
	}
	
	/**
	 * @return ?string
	 */
	public function getDescription(): ?string
	{
		return $this->_description;
	}
	
	/**
	 * @param string $type
	 *
	 * @return static
	 */
	public function setType(string $type): static
	{
		$this->_type = $type;
		
		return $this;
	}
	
	/**
	 * @return string
	 */
	public function getType(): string
	{
		return $this->_type;
	}
	
	/**
	 * @param bool $raw
	 *
	 * @return static
	 */
	public function setIsRaw(bool $raw = true): static
	{
		$this->_raw = $raw;
		
		return $this;
	}
	
	/**
	 * @return bool
	 */
	public function isRaw(): bool
	{
		return $this->_raw;
	}
	
	/**
	 * @return bool
	 */
	public function isInfo(): bool
	{
		return $this->getType() === self::TYPE_INFO;
	}
	
	/**
	 * @return bool
	 */
	public function isSuccess(): bool
	{
		return $this->getType() === self::TYPE_SUCCESS;
	}
	
	/**
	 * @return bool
	 */
	public function isWarning(): bool
	{
		return $this->getType() === self::TYPE_WARNING;
	}
	
	/**
	 * @return bool
	 */
	public function isError(): bool
	{
		return $this->getType() === self::TYPE_ERROR;
	}
	
	/**
	 * @return string
	 */
	public function __toString(): string
	{
		return View::messages()->__toString();
	}
}
