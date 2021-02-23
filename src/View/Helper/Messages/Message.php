<?php
declare(strict_types=1);

namespace Ovos\View\Helper\Messages;

use Ovos\View\Helper\Messages;

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
	 * @var Messages
	 */
	protected Messages $_messages;

	/**
	 * @var string
	 */
	protected string $_title;

	/**
	 * @var string
	 */
	protected string $_description;

	/**
	 * @var string
	 */
	protected string $_type;

	/**
	 * @param Messages $messages
	 * @param string $type
	 * @param string $description
	 * @param string $title
	 */
	public function __construct(Messages $messages,
		string $type = self::TYPE_SUCCESS,
		string $description = null,
		string $title = null)
	{
		$this->setMessages($messages);
		$this->setType($type);
		$this->setDescription($description);
		$this->setTitle($title);
	}

	/**
	 * @param Messages $messages
	 * 
	 * @return $this
	 */
	public function setMessages(Messages $messages): self
	{
		$this->_messages = $messages;
		
		return $this;
	}

	/**
	 * @param null|string $title
	 *
	 * @return $this
	 */
	public function setTitle(?string $title): self
	{
		$this->_title = $title;

		return $this;
	}

	/**
	 * @return null|string
	 */
	public function getTitle(): ?string
	{
		return $this->_title;
	}

	/**
	 * @param null|string $description
	 *
	 * @return $this
	 */
	public function setDescription(?string $description): self
	{
		$this->_description = $description;

		return $this;
	}

	/**
	 * @return null|string
	 */
	public function getDescription(): ?string
	{
		return $this->_description;
	}

	/**
	 * @param string $type
	 *
	 * @return $this
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
	public function __toString()
	{
		return $this->_messages->__toString();
	}
}
