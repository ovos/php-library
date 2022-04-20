<?php
declare(strict_types=1);

namespace Ovos\View\Helper\Messages;

use Ovos\View\Helper\Messages;
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
	public const TYPE_INFO = 'info';
	public const TYPE_SUCCESS = 'success';
	public const TYPE_WARNING = 'warning';
	public const TYPE_ERROR = 'error';
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
	 * @param Messages $messages
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
	 * @return self
	 */
	public function setTitle(?string $title): self
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
	 * @return self
	 */
	public function setDescription(?string $description): self
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
	 * @return self
	 */
	public function setType(string $type): self
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
	 * @return self
	 */
	public function setIsRaw(bool $raw = true): self
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
