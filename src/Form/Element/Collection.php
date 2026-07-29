<?php
declare(strict_types=1);

namespace Ovos\Form\Element;

use Ovos\Form\Element;
use Ovos\Form\Error;
use Override;

use function is_array;
use function sprintf;

/**
 * An element whose value is a list.
 *
 * The type gate rejects a scalar where an array belongs — a single field
 * error instead of a string reaching an array-shaped normalizer or a JSON
 * column. NULL passes the gate: for several list fields null is itself a
 * value ("no restriction"), and where it is not, the field's normalizer or
 * validators say so.
 *
 * Remember that FILTERS apply per item of an array value (the HTML
 * multi-input contract) — a whole-list transform belongs in a normalizer
 * or a cast, not a filter.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Collection extends Element
{
	public const string ERROR_TYPE = 'type_collection';
	
	public function __construct(
		protected string $typeMessage = '"%s" must be a list.',
	)
	{
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
		
		return $value === null || is_array($value);
	}
}
