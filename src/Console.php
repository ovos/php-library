<?php
declare(strict_types=1);

namespace Ovos;

use SplQueue;

use function count;

/**
 * Console
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Console
{
	/**
	 * Contains collected data
	 */
	protected SplQueue $messages;
	
	public static int $limit = 0;
	
	public function __construct()
	{
		$this->messages = new SplQueue;
	}
	
	/**
	 * Adds a message to a collection
	 */
	public function setMessage(
		mixed $message,
	): static
	{
		$this->messages->push([
			'message' => $message,
		]);
		
		// delete the oldest element from the queue if we reached the limit
		if(self::$limit && $this->messages->count() > self::$limit)
		{
			$this->messages->shift();
		}
		
		return $this;
	}
	
	public function setMessages(
		...$messages,
	): static
	{
		foreach($messages as $message)
		{
			$this->setMessage($message);
		}
		
		return $this;
	}
	
	/**
	 * Returns collected data
	 */
	public function getMessages(): SplQueue
	{
		return $this->messages;
	}
	
	/**
	 * Returns collected data
	 *
	 * @return ArrayObject[]
	 */
	public function getReport(): array
	{
		$messages = [];
		
		foreach($this->messages as $message)
		{
			$messages[] = new ArrayObject
			([
				'message' => $message['message'],
			]);
		}
		
		return $messages;
	}
	
	public function getCount(): int
	{
		return count($this->messages);
	}
}
