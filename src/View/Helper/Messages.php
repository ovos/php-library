<?php
declare(strict_types=1);

namespace Ovos\View\Helper;

use Ovos\Exception;
use Ovos\Service\Session;
use Ovos\View;
use Ovos\View\Helper;
use Ovos\View\Helper\Messages\Message;
use function Ovos\services;

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
	protected $_session;

	/**
	 * @var Message[]
	 */
	protected $_items = [];

	/**
	 */
	public function __construct()
	{
		parent::__construct();

		if($this->_session = services()->session)
		{
			$this->_items = &$this->_session->{self::SESSION_NAMESPACE};
			if($this->_items === null)
			{
				$this->_items = [];
			}
		}
	}

	/**
	 * @param string $type
	 * @param string $description
	 * @param string $title
	 *
	 * @return Message
	 */
	public function addMessage(string $type = null, string $description = null, string $title = null): Message
	{
		$message = new Message($this, $type, $description, $title);
		$this->_items[] = $message;

		return $message;
	}

	/**
	 * @param string $description
	 * @param string $title
	 * 
	 * @return Message
	 */
	public function addSuccess(string $description = null, string $title = null): Message
	{
		return $this->addMessage(Message::TYPE_SUCCESS, $description, $title);
	}

	/**
	 * @param string $description
	 * @param string $title
	 * 
	 * @return Message
	 */
	public function addInfo(string $description = null, string $title = null): Message
	{
		return $this->addMessage(Message::TYPE_INFO, $description, $title);
	}

	/**
	 * @param string $description
	 * @param string $title
	 * 
	 * @return Message
	 */
	public function addError(string $description = null, string $title = null): Message
	{
		return $this->addMessage(Message::TYPE_ERROR, $description, $title);
	}

	/**
	 * @param string $description
	 * @param string $title
	 * 
	 * @return Message
	 */
	public function addWarning(string $description = null, string $title = null): Message
	{
		return $this->addMessage(Message::TYPE_WARNING, $description, $title);
	}

	/**
	 * @return bool
	 */
	public function hasSuccess(): bool
	{
		foreach($this->_items as $item)
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
		foreach($this->_items as $item)
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
		if($this->_items === null
			|| \count($this->_items) === 0)
		{
			return '';
		}

		$view = new View('helpers/messages.phtml');
		return $view->render();
	}

	/**
	 * @return Message[]
	 */
	public function toArray(): array
	{
		$items = $this->_items; // copy
		$this->_items = null;

		return $items;
	}
}