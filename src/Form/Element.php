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
	 * @var array{0: callable, 1: ?string}[]
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
			foreach($this->normalizers as [$normalizer, $message])
			{
				$value = $normalizer($value);
				
				if($value === null)
				{
					$this->normalizerError = sprintf(
						$message ?? '"%s" is not valid.',
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
		callable $normalizer,
		?string $message = null,
	): static
	{
		$this->normalizers[] = [$normalizer, $message];
		
		return $this;
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
		$value = $this->getUserValue();
		
		// a normalizer rejected the value — its message is the field's one
		// error, and the validators never see the rejected input
		if($this->normalizerError !== null)
		{
			$this->addError(new Error('normalizer', $this->normalizerError));
			
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
