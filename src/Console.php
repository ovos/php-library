<?php
declare(strict_types=1);

namespace Ovos;

use SplQueue;

use function count;

/**
 * Console
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Console
{
	/**
	 * Collector instance
	 *
	 * @var ?self
	 */
	protected static ?self $instance = null;

	/**
	 * Contains collected data
	 *
	 * @var SplQueue
	 */
	protected SplQueue $_messages;

	/**
	 * @var int
	 */
	public static int $limit = 0;

	/**
	 * @return self
	 */
	public static function getInstance(): self
	{
		if(self::$instance === null)
		{
			self::$instance = new self;
		}

		return self::$instance;
	}
	
	/**
	 */
	public function __construct()
	{
		$this->_messages = new SplQueue;
	}
	
	/**
	 * Adds a message to collection
	 *
	 * @param mixed $message Message
	 *
	 * @return self
	 */
	public function setMessage(mixed $message): self
	{
		$this->_messages->push([
			'message' => $message,
		]);
		
		// delete the oldest element from the queue if we reached the limit
		if(self::$limit && $this->_messages->count() > self::$limit)
		{
			$this->_messages->shift();
		}
		
		return $this;
	}
	
	/**
	 * @param ...$messages
	 *
	 * @return self
	 */
	public function setMessages(...$messages): self
	{
		foreach($messages as $message)
		{
			$this->setMessage($message);
		}
		
		return $this;
	}
	
	/**
	 * Returns collected data
	 *
	 * @return SplQueue
	 */
	public function getMessages(): SplQueue
	{
		return $this->_messages;
	}
	
	/**
	 * Returns collected data
	 *
	 * @return ArrayObject[]
	 */
	public function getReport(): array
	{
		$messages = [];
		
		foreach($this->_messages as $message)
		{
			$messages[] = new ArrayObject
			([
				'message' => $message['message'],
			]);
		}
		
		return $messages;
	}
	
	/**
	 * @return int
	 */
	public function getCount(): int
	{
		return count($this->_messages);
	}
}
