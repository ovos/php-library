<?php
declare(strict_types=1);

namespace Ovos\View\Helper;

use Ovos\Service\Session;
use Ovos\View;
use Ovos\View\Helper;
use Ovos\View\Helper\Messages\Message;
use function Ovos\services;
use function count;

/**
 * Messages
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Messages extends Helper
{
	/**
	 * @var string
	 */
	public const SESSION_NAMESPACE = 'messages';

	/**
	 * @var Session
	 */
	protected Session $_session;
	
	/**
	 * Internal namespace
	 * 
	 * @var null|string
	 */
	protected null|string $_namespace;

	/**
	 * @var Message[]
	 */
	protected null|array $_items = [];

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
	 * @param null|string $namespace
	 * 
	 * @return self
	 */
	public function messages(null|string $namespace = null): self
	{
		$this->setNamespace($namespace);
		
		return $this;
	}

	/**
	 * @param null|string $namespace
	 *
	 * @return $this
	 */
	public function setNamespace(null|string $namespace): self
	{
		$this->_namespace = $namespace;

		return $this;
	}

	/**
	 * @return null|string
	 */
	public function getNamespace(): null|string
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
		
		if(!isset($this->_items[$this->_namespace]))
		{
			$this->_items[$this->_namespace] = [];
		}
		
		return $this->_items[$this->_namespace];
	}

	/**
	 * @param null|string $type
	 * @param null|string $description
	 * @param null|string $title
	 *
	 * @return Message
	 */
	public function addMessage(null|string $type = null,
		null|string $description = null,
		null|string $title = null
	): Message
	{
		$message = new Message($this, $type, $description, $title);
		$this->getItems()[] = $message;

		return $message;
	}

	/**
	 * @param null|string $description
	 * @param null|string $title
	 * 
	 * @return Message
	 */
	public function addSuccess(
		null|string $description = null,
		null|string $title = null): Message
	{
		return $this->addMessage(Message::TYPE_SUCCESS, $description, $title);
	}

	/**
	 * @param null|string $description
	 * @param null|string $title
	 * 
	 * @return Message
	 */
	public function addInfo(null|string $description = null,
		null|string $title = null): Message
	{
		return $this->addMessage(Message::TYPE_INFO, $description, $title);
	}

	/**
	 * @param null|string $description
	 * @param null|string $title
	 * 
	 * @return Message
	 */
	public function addError(null|string $description = null,
		null|string $title = null): Message
	{
		return $this->addMessage(Message::TYPE_ERROR, $description, $title);
	}

	/**
	 * @param null|string $description
	 * @param null|string $title
	 * 
	 * @return Message
	 */
	public function addWarning(null|string $description = null,
		null|string $title = null): Message
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
		if(count($this->getItems()) === 0)
		{
			return '';
		}

		$view = new View('helpers/messages.phtml');
		return $view->render();
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
