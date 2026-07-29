<?php
declare(strict_types=1);

namespace Ovos\Form;

use Ovos\Exception;
use Ovos\Form;

use function count;
use function is_array;
use function sprintf;

/**
 * Element
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Element
{
	protected string $id;
	
	protected ?Form $form = null;
	
	protected null|string|bool|int|float|array $value = null;
	
	protected ?string $label = null;
	
	/**
	 * @var Validator[]
	 */
	protected array $validators = [];
	
	/**
	 * @var Filter[]
	 */
	protected array $filters = [];
	
	/**
	 * Whole-value transform-or-reject steps — see addNormalizer()
	 *
	 * @var Normalizer[]
	 */
	protected array $normalizers = [];
	
	/**
	 * The message of the normalizer that rejected the current value;
	 * null while the value passes
	 */
	protected ?string $normalizerError = null;
	
	/**
	 * Post-validation transforms — see addCast()
	 *
	 * @var callable[]
	 */
	protected array $casts = [];
	
	/**
	 * The type gate — see asText()/asNumber()/asCollection(). A VALIDATOR
	 * (Validator\Text|Number|Collection) that judges the RAW value before
	 * filters, normalizers and validators run: a value of the wrong shape is
	 * the field's single error, and the message lives on the validator,
	 * overwritable exactly like every other rule's.
	 */
	protected ?Validator $gate = null;
	
	/**
	 * @var Error[]
	 */
	protected array $errors = [];
	
	public function setId(
		string $id,
	): static
	{
		$this->id = $id;
		
		return $this;
	}
	
	public function getId(
		bool $withFormId = true,
	): string
	{
		$id = $this->id;
		if($withFormId
			&& $formId = $this->form->getId())
		{
			$id = $formId . '_' . $id;
		}
		
		return $id;
	}
	
	public function getName(
		bool $withFormId = true,
	): string
	{
		$name = $this->id;
		if($withFormId
			&& $formId = $this->form->getId())
		{
			$name = $formId . '[' . $name . ']';
		}
		
		return $name;
	}
	
	public function setForm(
		?Form $form,
	): static
	{
		$this->form = $form;
		
		return $this;
	}
	
	public function getForm(): ?Form
	{
		if($this->form === null)
		{
			throw new Exception(
				'The element is not yet assigned to a form.');
		}
		
		return $this->form;
	}
	
	public function setValue(
		null|string|bool|int|float|array $value,
	): static
	{
		$this->reset(); // clear cache of getValue()
		$this->getForm()
			->setValue($this->id, $value);
		
		return $this;
	}
	
	public function getValue(
		bool $default = false,
	): null|string|bool|int|float|array
	{
		$value = $this->form
			->getRawValue($this->id);
		
		// return the default value, if no other value is present
		// do not filter it, we assume it's in filtered state
		if($value === null
			&& $default === true)
		{
			return $this->form
				->getDefaultValue($this->id);
		}
		
		// if a default value was not requested, process our value & cache it for future calls
		// some filters also process null values (for example, casting to int)
		if($this->value === null)
		{
			if(is_array($value))
			{
				foreach($value as &$item)
				{
					$item = $this->filterValue($item);
				}
				unset($item);
			}
			else
			{
				$value = $this->filterValue($value);
			}
			
			// normalizers run AFTER the filters and always on the value as a
			// WHOLE — filters apply per item of an array value (the HTML
			// multi-input contract), which is exactly what a whole-array
			// normalizer cannot live with
			$this->normalizerError = null;
			foreach($this->normalizers as $normalizer)
			{
				$value = $normalizer->normalize($value);
				
				if($value === null)
				{
					$this->normalizerError = sprintf(
						(string)$normalizer->getMessage(Normalizer::ERROR_NORMALIZER),
						$this->getName(),
					);
					
					break;
				}
			}
			
			$this->value = $value;
		}
		
		// return cached value
		return $this->value;
	}
	
	public function getInputValue(
	): null|string|bool|int|float|array
	{
		return $this->getValue(true);
	}
	
	public function getUserValue(
	): null|string|bool|int|float|array
	{
		return $this->getValue(false);
	}
	
	public function reset(): static
	{
		$this->value = null;
		$this->normalizerError = null;
		
		return $this;
	}
	
	public function setLabel(
		?string $label,
	): static
	{
		$this->label = $label;
		
		return $this;
	}
	
	public function getLabel(): ?string
	{
		return $this->label;
	}
	
	public function setDefault(
		null|string|bool|int|float|array $default,
	): static
	{
		$this->getForm()
			->setDefault($this->id, $default);
		
		return $this;
	}
	
	public function filterValue(
		mixed $value,
	): mixed
	{
		foreach($this->filters as $filter)
		{
			$value = $filter->filter($value);
		}
		
		return $value;
	}
	
	public function addFilter(
		Filter $filter,
	): static
	{
		$this->filters[] = $filter;
		
		return $this;
	}
	
	/**
	 * A whole-value transform-or-reject step, for input a filter cannot
	 * express: it runs AFTER the filters, always on the value as a whole
	 * (filters apply per item of an array value — the HTML multi-input
	 * contract), its return becomes the element's value, and returning NULL
	 * rejects the value — isValid() then reports the message and skips the
	 * validators, so a field carries one error, not a cascade.
	 *
	 * For a field where null is itself a legal value, use validators: a
	 * normalizer is for fields whose null means "invalid". The message
	 * follows the validator convention — %s becomes the element name.
	 */
	public function addNormalizer(
		callable|Normalizer $normalizer,
		?string $message = null,
	): static
	{
		if($normalizer instanceof Normalizer === false)
		{
			$normalizer = new Normalizer\Callback($normalizer);
		}
		
		if($message !== null)
		{
			$normalizer->setMessage(Normalizer::ERROR_NORMALIZER, $message);
		}
		
		$this->normalizers[] = $normalizer;
		
		return $this;
	}
	
	/**
	 * @return Normalizer[]
	 */
	public function getNormalizers(): array
	{
		return $this->normalizers;
	}
	
	/**
	 * A post-validation transform, applied by getCastValue() only — after
	 * filters, normalizers and validators have all seen the untransformed
	 * value. For the storage form ("0 stores as null", "the full set
	 * collapses", "the flag becomes 1/0") where transforming earlier would
	 * hide what validation needs to see.
	 */
	public function addCast(
		callable $cast,
	): static
	{
		$this->casts[] = $cast;
		
		return $this;
	}
	
	/**
	 * The value with the casts applied — the storage form
	 */
	public function getCastValue(): null|string|bool|int|float|array
	{
		$value = $this->getValue();
		
		foreach($this->casts as $cast)
		{
			$value = $cast($value);
		}
		
		return $value;
	}
	
	/**
	 * Type the element as text: a non-scalar value (a JSON array or object
	 * where a string belongs) is a single field error, and the storage form
	 * (getCastValue()) is the string. NULL passes the gate — whether the
	 * field may be absent or empty stays the business of the validators.
	 *
	 * The as*() family types the element the form's own way — on first
	 * access, in the same chain that configures it:
	 *
	 *   $this->title->asText()->addValidator(new Validator\NotEmpty);
	 *
	 * The Element\Text|Number|Flag|Collection classes are the same thing
	 * spelled as a class, for setElement().
	 */
	public function asText(
		?string $message = null,
	): static
	{
		$this->gate = new Validator\Text;
		if($message !== null)
		{
			$this->gate->withMessage($message);
		}
		
		return $this->addCast(static fn(mixed $value): ?string
			=> $value === null ? null : (string)$value);
	}
	
	/**
	 * Type the element as a number: anything not numeric ("12abc") is a
	 * single field error, never a silent (int) cast downstream. NULL and ''
	 * pass the gate as "unset" (the notion Validator\Range uses) — pair with
	 * Range(nullable: false) for a NOT NULL column. The storage form is the
	 * actual int|float ("15" becomes 15); null and '' become null.
	 */
	public function asNumber(
		?string $message = null,
	): static
	{
		$this->gate = new Validator\Number;
		if($message !== null)
		{
			$this->gate->withMessage($message);
		}
		
		return $this->addCast(static fn(mixed $value): null|int|float
			=> $value === null || $value === '' ? null : $value + 0);
	}
	
	/**
	 * Type the element as an on/off switch. No gate and no rules: anything
	 * truthy counts as on — a JSON true, an HTML checkbox's "on", a 1 — and
	 * the storage form is 1/0, which is what a TINYINT column wants. A sent
	 * null is off, so a NOT NULL column never meets a NULL.
	 */
	public function asFlag(): static
	{
		return $this->addCast(static fn(mixed $value): int => $value ? 1 : 0);
	}
	
	/**
	 * Type the element as a list: a scalar where an array belongs is a
	 * single field error, before it could reach an array-shaped normalizer
	 * or a JSON column. NULL passes the gate — for several list fields null
	 * is itself a value ("no restriction"), and where it is not, the field's
	 * normalizer or validators say so.
	 *
	 * Remember that FILTERS apply per item of an array value (the HTML
	 * multi-input contract) — a whole-list transform belongs in a normalizer
	 * or a cast, not a filter.
	 */
	public function asCollection(
		?string $message = null,
	): static
	{
		$this->gate = new Validator\Collection;
		if($message !== null)
		{
			$this->gate->withMessage($message);
		}
		
		return $this;
	}
	
	/**
	 * The type gate's validator — overwrite its message from outside exactly
	 * as on any validator: getGate()?->withMessage(…)
	 */
	public function getGate(): ?Validator
	{
		return $this->gate;
	}
	
	public function addValidator(
		Validator $validator,
	): static
	{
		$this->validators[] = $validator;
		
		return $this;
	}
	
	public function isValid(): bool
	{
		$this->errors = []; // reset errors
		
		// the type gate judges the RAW value — a wrong shape is this single
		// field error, before a filter, normalizer or validator could trip
		// over it
		if($this->gate !== null)
		{
			$this->gate->setElement($this);
			$this->gate->clearErrors();
			
			if($this->gate->isValid($this->getForm()
				->getRawValue($this->getId(withFormId: false))) === false)
			{
				$this->addErrors($this->gate->getErrors());
				
				return false;
			}
		}
		
		$value = $this->getUserValue();
		
		// a normalizer rejected the value — its message is the field's one
		// error, and the validators never see the rejected input
		if($this->normalizerError !== null)
		{
			$this->addError(new Error(Normalizer::ERROR_NORMALIZER,
				$this->normalizerError));
			
			return false;
		}
		
		$isValid = true;
		
		foreach($this->validators as $validator)
		{
			$validator->setElement($this);
			if($validator->isValid($value) === false)
			{
				$isValid = false;
				$this->addErrors($validator->getErrors());
			}
		}
		
		return $isValid;
	}
	
	public function addError(
		Error $error,
	): static
	{
		$error->setElement($this);
		$this->errors[] = $error;
		
		return $this;
	}
	
	/**
	 * @param Error[] $errors
	 */
	public function addErrors(
		array $errors,
	): static
	{
		foreach($errors as $error)
		{
			$this->addError($error);
		}
		
		return $this;
	}
	
	public function hasErrors(): bool
	{
		return count($this->errors) > 0;
	}
	
	public function clearErrors(): static
	{
		$this->errors = [];
		
		return $this;
	}
	
	/**
	 * @return Error[]
	 */
	public function getErrors(): array
	{
		return $this->errors;
	}
	
	public function __toString(): string
	{
		return (string)$this->getValue();
	}
	
	public function __debugInfo(): array
	{
		return [
			$this->getValue(),
		];
	}
}
