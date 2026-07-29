<?php
declare(strict_types=1);

namespace Ovos\Form;

/**
 * Normalizer — the whole-value transform-or-reject step of an element's
 * pipeline, completing the class family alongside Filter and Validator.
 * Runs after the filters, always on the value as a WHOLE (filters apply per
 * item of an array value); the return value becomes the element's value,
 * and returning NULL rejects it with this normalizer's message.
 *
 * addNormalizer() also accepts a plain callable and wraps it in
 * Normalizer\Callback; subclass this for reusable rules.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Normalizer
{
	// Errors
	public const string ERROR_NORMALIZER = 'normalizer';
	
	/**
	 * The plain English default — overwrite from outside via setMessage(),
	 * exactly as on a validator; %s becomes the element name
	 */
	protected array $messages = [
		self::ERROR_NORMALIZER => '"%s" is not valid.',
	];
	
	/**
	 * The normalized value — or NULL to reject
	 */
	abstract public function normalize(
		mixed $value,
	): mixed;
	
	public function getMessages(): array
	{
		return $this->messages;
	}
	
	public function setMessage(
		string $errorCode,
		string $value,
	): static
	{
		$this->messages[$errorCode] = $value;
		
		return $this;
	}
	
	public function getMessage(
		string $errorCode,
	): ?string
	{
		return $this->messages[$errorCode] ?? null;
	}
}
