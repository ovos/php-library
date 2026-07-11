<?php
declare(strict_types=1);

namespace Ovos\Service\Console;

use Throwable;

use function array_merge;

/**
 * A mutable console event: build it up — message, an optional exception,
 * priority, extras and per-event context overrides — then capture() it onto
 * the bound Sender's queue. A thrown exception is optional; anything with a
 * message can be reported. The single HTTP flush still happens once per
 * request from the Sender (see Sender::flush()).
 *
 * @author Marcin Gil <mg@ovos.at>
 */
final class Event
{
	protected ?Throwable $throwable = null;
	
	protected string $message = '';
	
	protected ?int $priority = null;
	
	protected array $extra = [];
	
	/**
	 * Per-event context overrides, merged over the request/CLI context the
	 * Sender builds at flush time — these win on key collisions.
	 */
	protected array $context = [];
	
	public function __construct(
		protected readonly ?Sender $sender = null,
	)
	{
	}
	
	public function exception(
		?Throwable $throwable,
	): static
	{
		$this->throwable = $throwable;
		
		return $this;
	}
	
	public function message(
		string $message,
	): static
	{
		$this->message = $message;
		
		return $this;
	}
	
	public function priority(
		?int $priority,
	): static
	{
		$this->priority = $priority;
		
		return $this;
	}
	
	/**
	 * Set a single extra value (arbitrary caller context).
	 */
	public function extra(
		string $key,
		mixed $value,
	): static
	{
		$this->extra[$key] = $value;
		
		return $this;
	}
	
	/**
	 * Merge a set of extra values.
	 */
	public function extras(
		array $extra,
	): static
	{
		$this->extra = array_merge($this->extra, $extra);
		
		return $this;
	}
	
	/**
	 * Override a single context field (wins over the auto-built context).
	 */
	public function context(
		string $key,
		mixed $value,
	): static
	{
		$this->context[$key] = $value;
		
		return $this;
	}
	
	/**
	 * Merge a set of context overrides.
	 */
	public function contexts(
		array $context,
	): static
	{
		$this->context = array_merge($this->context, $context);
		
		return $this;
	}
	
	public function getThrowable(): ?Throwable
	{
		return $this->throwable;
	}
	
	/**
	 * Complete v1 error object. The Sender merges the request/CLI context onto
	 * the "context" key (these overrides winning) and enforces the log level at
	 * flush time.
	 */
	public function toPayload(): array
	{
		if($this->throwable !== null)
		{
			$payload = Payload::fromThrowable(
				$this->throwable,
				$this->priority,
				$this->extra,
				$this->sender?->capturesSource() ?? true,
			);
			
			// an explicit message overrides the exception message
			if($this->message !== '')
			{
				$payload['message'] = $this->message;
			}
		}
		else
		{
			$payload = Payload::fromMessage(
				$this->message,
				$this->priority ?? Priority::NOTICE,
				$this->extra,
			);
		}
		
		$payload['context'] = $this->context;
		
		return $payload;
	}
	
	/**
	 * Enqueue this event onto the bound Sender (no-op when unbound).
	 */
	public function capture(): static
	{
		$this->sender?->capture($this);
		
		return $this;
	}
}
