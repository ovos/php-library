<?php
declare(strict_types=1);

namespace Ovos\View\Helper\Messages;

use Ovos\View;

/**
 * Message
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Message
{
	// Types
	public const string TYPE_INFO = 'info';
	public const string TYPE_SUCCESS = 'success';
	public const string TYPE_WARNING = 'warning';
	public const string TYPE_ERROR = 'error';
	
	protected ?string $title;
	
	protected ?string $description;
	
	protected string $type;
	
	protected bool $raw;
	
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
	
	public function setTitle(
		?string $title,
	): static
	{
		$this->title = $title;
		
		return $this;
	}
	
	public function getTitle(): ?string
	{
		return $this->title;
	}
	
	public function setDescription(
		?string $description,
	): static
	{
		$this->description = $description;
		
		return $this;
	}
	
	public function getDescription(): ?string
	{
		return $this->description;
	}
	
	public function setType(
		string $type,
	): static
	{
		$this->type = $type;
		
		return $this;
	}
	
	public function getType(): string
	{
		return $this->type;
	}
	
	public function setIsRaw(
		bool $raw = true,
	): static
	{
		$this->raw = $raw;
		
		return $this;
	}
	
	public function isRaw(): bool
	{
		return $this->raw;
	}
	
	public function isInfo(): bool
	{
		return $this->getType() === self::TYPE_INFO;
	}
	
	public function isSuccess(): bool
	{
		return $this->getType() === self::TYPE_SUCCESS;
	}
	
	public function isWarning(): bool
	{
		return $this->getType() === self::TYPE_WARNING;
	}
	
	public function isError(): bool
	{
		return $this->getType() === self::TYPE_ERROR;
	}
	
	public function __toString(): string
	{
		return View::messages()
			->__toString();
	}
}
