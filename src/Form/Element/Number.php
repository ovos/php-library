<?php
declare(strict_types=1);

namespace Ovos\Form\Element;

use Ovos\Form\Element;
use Ovos\Form\Error;
use Override;

use function is_numeric;
use function sprintf;

/**
 * An element whose value is a number.
 *
 * The type gate rejects anything that is not numeric — "12abc" is a field
 * error here, never a silent (int) cast to 12 somewhere downstream. NULL
 * and '' pass the gate as "unset" (the same notion Validator\Range uses):
 * whether the field may be unset is the validators' business — pair with
 * Range(nullable: false) for a NOT NULL column.
 *
 * The storage form is the actual number: getCastValue() answers int|float
 * ("15" becomes 15, "1.5" becomes 1.5); null and '' become null.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Number extends Element
{
	public const string ERROR_TYPE = 'type_number';
	
	public function __construct(
		protected string $typeMessage = '"%s" must be a number.',
	)
	{
		$this->addCast(static fn(mixed $value): null|int|float
			=> $value === null || $value === '' ? null : $value + 0);
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
		
		return $value === null || $value === '' || is_numeric($value);
	}
}
