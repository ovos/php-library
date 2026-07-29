<?php
declare(strict_types=1);

namespace Ovos\Form\Normalizer;

use Ovos\Form\Normalizer;
use Closure;

/**
 * Callback — a plain callable as a Normalizer; what addNormalizer() builds
 * when handed a callable. Unlike Validator\Callback the closure is NOT
 * rebound: a normalizer's message lives on the instance, not inside the
 * closure, so `static fn` stays the natural way to write one.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Callback extends Normalizer
{
	protected Closure $callback;
	
	public function __construct(
		callable $callback,
		?string $message = null,
	)
	{
		$this->callback = $callback(...);
		
		if($message !== null)
		{
			$this->setMessage(self::ERROR_NORMALIZER, $message);
		}
	}
	
	public function normalize(
		mixed $value,
	): mixed
	{
		return ($this->callback)($value);
	}
}
