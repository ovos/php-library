<?php
declare(strict_types=1);

namespace Ovos\Form;

use Ovos\Exception;
use Ovos\Form;

use function count;
use function is_array;

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
	
	protected bool $valueProcessed = false;
	
	protected ?string $label = null;
	
	/**
	 * @var array<array{validator: Validator, breakOnFailure: bool}>
	 */
	protected array $validators = [];
	
	/**
	 * @var array<array{filter: Filter, each: bool}>
	 */
	protected array $filters = [];
	
	/**
	 * Validators applied to raw input, before filters.
	 * @var array<array{validator: Validator, breakOnFailure: bool}>
	 */
	protected array $inputValidators = [];
	
	protected bool $inputValid = true;
	
	/** @var Error[] */
	protected array $inputErrors = [];
	
	/** @var array<callable|Filter> */
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
		$value = $this->getForm()->getRawValue($this->id);
		
		// Defaults are already prepared for display.
		if($value === null && $default)
		{
			return $this->getForm()->getDefaultValue($this->id);
		}
		
		if($this->valueProcessed === false)
		{
			[$this->inputValid, $this->inputErrors] = $this->validateValue($value, $this->inputValidators);
			$this->value = $this->inputValid ? $this->filterValue($value) : null;
			$this->valueProcessed = true;
		}
		
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
		$this->valueProcessed = false;
		$this->inputValid = true;
		$this->inputErrors = [];
		
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
		foreach($this->filters as ['filter' => $filter, 'each' => $each])
		{
			if($each && is_array($value))
			{
				foreach($value as &$item)
				{
					$item = $filter->filter($item);
				}
				unset($item);
			}
			else
			{
				$value = $filter->filter($value);
			}
		}
		
		return $value;
	}
	
	/** Set $each to false to filter an array as a whole. */
	public function addFilter(
		Filter $filter,
		bool $each = true,
	): static
	{
		$this->filters[] = ['filter' => $filter, 'each' => $each];
		$this->reset();
		
		return $this;
	}
	
	/** Output conversion, applied only by getCastValue(). */
	public function addCast(
		callable|Filter $cast,
	): static
	{
		$this->casts[] = $cast;
		
		return $this;
	}
	
	/** Call after successful validation when preparing values for storage. */
	public function getCastValue(): null|string|bool|int|float|array
	{
		$value = $this->getValue();
		
		foreach($this->casts as $cast)
		{
			$value = $cast instanceof Filter ? $cast->filter($value) : $cast($value);
		}
		
		return $value;
	}
	
	/** A failed input validator prevents filtering and subsequent validation. */
	public function addInputValidator(
		Validator $validator,
	): static
	{
		$this->inputValidators[] = ['validator' => $validator, 'breakOnFailure' => true];
		$this->reset();
		
		return $this;
	}
	
	public function addValidator(
		Validator $validator,
		bool $breakOnFailure = false,
	): static
	{
		$this->validators[] = ['validator' => $validator, 'breakOnFailure' => $breakOnFailure];
		
		return $this;
	}
	
	/** @return array{bool, Error[]} */
	protected function validateValue(
		mixed $value,
		array $validators,
	): array
	{
		$isValid = true;
		$errors = [];
		
		foreach($validators as ['validator' => $validator])
		{
			$validator->setElement($this);
			$validator->clearErrors();
		}
		
		foreach($validators as ['validator' => $validator, 'breakOnFailure' => $breakOnFailure])
		{
			if($validator->isValid($value) === false)
			{
				$isValid = false;
				$errors = [...$errors, ...$validator->getErrors()];
				if($breakOnFailure)
				{
					break;
				}
			}
		}
		
		return [$isValid, $errors];
	}
	
	public function isValid(): bool
	{
		$this->clearErrors();
		$value = $this->getUserValue();
		
		if($this->inputValid === false)
		{
			$this->addErrors($this->inputErrors);
			
			return false;
		}
		
		[$isValid, $errors] = $this->validateValue($value, $this->validators);
		$this->addErrors($errors);
		
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
