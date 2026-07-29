<?php
declare(strict_types=1);

namespace Ovos\Form\Element;

use Ovos\Form\Element;
use Ovos\Form\Error;
use Override;

use function is_scalar;
use function sprintf;

/**
 * An element whose value is a piece of text.
 *
 * The type gate runs first: a non-scalar value (a JSON array or object
 * where a string belongs) is a single field error, before any filter or
 * validator could trip over it — a hostile array otherwise reaches the
 * validators as-is, and (string)-casting it warns. NULL passes the gate:
 * whether the field may be absent or empty stays the business of the
 * validators (NotEmpty), exactly as for every other element.
 *
 * The storage form is the string: getCastValue() answers with the scalar
 * cast to one (null stays null), so an int 5 sent where "5" was meant
 * stores as the string.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Text extends Element
{
	public const string ERROR_TYPE = 'type_text';
	
	public function __construct(
		protected string $typeMessage = '"%s" must be text.',
	)
	{
		$this->addCast(static fn(mixed $value): ?string
			=> $value === null ? null : (string)$value);
	}
	
	#[Override]
	public function isValid(): bool
	{
		if($this->conforms() === false)
		{
			$this->errors = [];
			$this->addError(new Error(static::ERROR_TYPE,
				sprintf($this->typeMessage, $this->getName())));
			
			return false;
		}
		
		return parent::isValid();
	}
	
	protected function conforms(): bool
	{
		$value = $this->getForm()->getRawValue($this->getId(withFormId: false));
		
		return $value === null || is_scalar($value);
	}
}
