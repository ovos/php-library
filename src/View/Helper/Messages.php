<?php
declare(strict_types=1);

namespace Ovos\View\Helper;

use Ovos\Service\Disabled;
use Ovos\Service\Session;
use Ovos\View;
use Ovos\View\Helper;
use Ovos\View\Helper\Messages\Message;
use Countable;

use function count;

/**
 * Messages
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Messages extends Helper implements Countable
{
	public const string SESSION_NAMESPACE = 'messages';
	
	protected Session|Disabled $session;
	
	/**
	 * Internal namespace
	 */
	protected ?string $namespace;
	
	/**
	 * @var ?Message[]
	 */
	protected ?array $items = [];
	
	public function __construct()
	{
		parent::__construct();
		
		$this->session = $this->container->get(Session::SYMBOL);
		if($this->session instanceof Session)
		{
			$this->items = &$this->session->{self::SESSION_NAMESPACE};
		}
	}
	
	public function messages(
		?string $namespace = null,
	): static
	{
		$this->setNamespace($namespace);
		
		return $this;
	}
	
	public function setNamespace(
		?string $namespace,
	): static
	{
		$this->namespace = $namespace;
		
		return $this;
	}
	
	public function getNamespace(): ?string
	{
		return $this->namespace;
	}
	
	/**
	 * @return Message[]
	 */
	public function &getItems(): array
	{
		if($this->items === null)
		{
			$this->items = [];
		}
		
		if($this->namespace === null)
		{
			return $this->items;
		}
		
		if(isset($this->items[$this->namespace]) === false)
		{
			$this->items[$this->namespace] = [];
		}
		
		return $this->items[$this->namespace];
	}
	
	public function addMessage(
		?string $type = null,
		?string $description = null,
		?string $title = null,
	): Message
	{
		$message = new Message($type, $description, $title);
		$this->getItems()[] = $message;
		
		return $message;
	}
	
	public function addSuccess(
		?string $description = null,
		?string $title = null,
	): Message
	{
		return $this->addMessage(Message::TYPE_SUCCESS, 
			$description,
			$title,
		);
	}
	
	public function addInfo(
		?string $description = null,
		?string $title = null,
	): Message
	{
		return $this->addMessage(Message::TYPE_INFO,
			$description,
			$title,
		);
	}
	
	public function addError(
		?string $description = null,
		?string $title = null,
	): Message
	{
		return $this->addMessage(Message::TYPE_ERROR,
			$description,
			$title,
		);
	}
	
	public function addWarning(
		?string $description = null,
		?string $title = null,
	): Message
	{
		return $this->addMessage(Message::TYPE_WARNING,
			$description,
			$title,
		);
	}
	
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
		$this->items = null;
		
		return $items;
	}
}
