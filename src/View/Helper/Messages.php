<?php
declare(strict_types=1);

namespace Ovos\View\Helper;

use Ovos\Service\Disabled;
use Ovos\Service\Session;
use Ovos\View;
use Ovos\View\Helper;
use Ovos\View\Helper\Messages\Message;
use Countable;

use function Ovos\services;
use function count;

/**
 * Messages
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Messages extends Helper implements Countable
{
	/**
	 * @var string
	 */
	public const string SESSION_NAMESPACE = 'messages';
	
	/**
	 * @var Session|Disabled
	 */
	protected Session|Disabled $_session;
	
	/**
	 * Internal namespace
	 * 
	 * @var ?string
	 */
	protected ?string $_namespace;
	
	/**
	 * @var ?Message[]
	 */
	protected ?array $_items = [];
	
	/**
	 */
	public function __construct()
	{
		parent::__construct();
		
		$this->_session = services()->session;
		if($this->_session->isEnabled())
		{
			$this->_items = &$this->_session->{self::SESSION_NAMESPACE};
		}
	}
	
	/**
	 * @param ?string $namespace
	 * 
	 * @return self
	 */
	public function messages(?string $namespace = null): self
	{
		$this->setNamespace($namespace);
		
		return $this;
	}
	
	/**
	 * @param ?string $namespace
	 *
	 * @return self
	 */
	public function setNamespace(?string $namespace): self
	{
		$this->_namespace = $namespace;
		
		return $this;
	}
	
	/**
	 * @return ?string
	 */
	public function getNamespace(): ?string
	{
		return $this->_namespace;
	}
	
	/**
	 * @return Message[]
	 */
	public function &getItems(): array
	{
		if($this->_items === null)
		{
			$this->_items = [];
		}
		
		if($this->_namespace === null)
		{
			return $this->_items;
		}
		
		if(isset($this->_items[$this->_namespace]) === false)
		{
			$this->_items[$this->_namespace] = [];
		}
		
		return $this->_items[$this->_namespace];
	}

	/**
	 * @param ?string $type
	 * @param ?string $description
	 * @param ?string $title
	 *
	 * @return Message
	 */
	public function addMessage(?string $type = null,
		?string $description = null,
		?string $title = null
	): Message
	{
		$message = new Message($type, $description, $title);
		$this->getItems()[] = $message;
		
		return $message;
	}
	
	/**
	 * @param ?string $description
	 * @param ?string $title
	 * 
	 * @return Message
	 */
	public function addSuccess(
		?string $description = null,
		?string $title = null): Message
	{
		return $this->addMessage(Message::TYPE_SUCCESS, $description, $title);
	}
	
	/**
	 * @param ?string $description
	 * @param ?string $title
	 * 
	 * @return Message
	 */
	public function addInfo(?string $description = null,
		?string $title = null): Message
	{
		return $this->addMessage(Message::TYPE_INFO, $description, $title);
	}
	
	/**
	 * @param ?string $description
	 * @param ?string $title
	 * 
	 * @return Message
	 */
	public function addError(?string $description = null,
		?string $title = null): Message
	{
		return $this->addMessage(Message::TYPE_ERROR, $description, $title);
	}
	
	/**
	 * @param ?string $description
	 * @param ?string $title
	 * 
	 * @return Message
	 */
	public function addWarning(?string $description = null,
		?string $title = null): Message
	{
		return $this->addMessage(Message::TYPE_WARNING, $description, $title);
	}
	
	/**
	 * @return bool
	 */
	public function hasSuccess(): bool
	{
		foreach($this->getItems() as $item)
		{
			if($item->isSuccess())
			{
				return true;
			}
		}
		
		return false;
	}
	
	/**
	 * @return bool
	 */
	public function hasError(): bool
	{
		foreach($this->getItems() as $item)
		{
			if($item->isError())
			{
				return true;
			}
		}
		
		return false;
	}
	
	/**
	 * @return string
	 */
	public function __toString(): string
	{
		if($this->count() === 0)
		{
			return '';
		}
		
		$view = new View('helpers/messages.phtml');
		$view->messages = $this->toArray();
		
		return $view->render();
	}
	
	/**
	 * @return int
	 */
	public function count(): int
	{
		return count($this->getItems());
	}
	
	/**
	 * Returns messages and empties the list
	 * 
	 * @return Message[]
	 */
	public function toArray(): array
	{
		$items = $this->getItems(); // copy
		$this->_items = null;
		
		return $items;
	}
}
