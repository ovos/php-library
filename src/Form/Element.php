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
 * @package Ovos
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
