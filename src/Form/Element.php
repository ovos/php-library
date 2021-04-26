<?php
declare(strict_types=1);

namespace Ovos\Form;

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
	/**
	 * @var string
	 */
	protected string $_id;

	/**
	 * @var Form
	 */
	protected Form $_form;

	/**
	 * @var null|string|int|float|array
	 */
	protected null|string|int|float|array $_value = null;

	/**
	 * @var ?string
	 */
	protected ?string $_label = null;

	/**
	 * @var Validator[]
	 */
	protected array $_validators = [];

	/**
	 * @var Filter[]
	 */
	protected array $_filters = [];

	/**
	 * @var Error[]
	 */
	protected array $_errors = [];

	/**
	 * @param string $id
	 * 
	 * @return self
	 */
	public function setId(string $id): self
	{
		$this->_id = $id;
		
		return $this;
	}

	/**
	 * @return string
	 */
	public function getId(): string
	{
		$id = $this->_id;
		if($formId = $this->_form->getId())
		{
			$id = $formId . '_' . $id;
		}

		return $id;
	}

	/**
	 * @return string
	 */
	public function getName(): string
	{
		$name = $this->_id;
		if($formId = $this->_form->getId())
		{
			$name = $formId . '[' . $name . ']';
		}

		return $name;
	}

	/**
	 * @param Form $form
	 *
	 * @return self
	 */
	public function setForm(Form $form): self
	{
		$this->_form = $form;

		return $this;
	}

	/**
	 * @return Form
	 */
	public function getForm(): Form
	{
		return $this->_form;
	}

	/**
	 * @param null|string|int|float|array $value
	 *
	 * @return self
	 */
	public function setValue($value): self
	{
		$this->_value = null; // clear cache of getValue()
		$this->getForm()->setValue($this->_id, $value);

		return $this;
	}

	/*
	 * @return null|string|int|float|array
	 */
	public function getValue(): null|string|int|float|array
	{
		if($this->_value === null)
		{
			$value = $this->_form->getValue($this->_id);
			if($value === null)
			{
				return null;
			}
			
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

			$this->_value = $value;
		}

		return $this->_value;
	}

	/**
	 * @param ?string $label
	 * 
	 * @return self
	 */
	public function setLabel(?string $label): self
	{
		$this->_label = $label;
		
		return $this;
	}

	/**
	 * @return ?string
	 */
	public function getLabel(): ?string
	{
		return $this->_label;
	}

	/**
	 * @param null|string|int|float|array $default
	 *
	 * @return self
	 */
	public function setDefault($default): self
	{
		$this->getForm()->setDefault($this->_id, $default);

		return $this;
	}	

	/**
	 * @param mixed $value
	 * 
	 * @return int|string
	 */
	public function filterValue(mixed $value)
	{
		foreach($this->_filters as $filter)
		{
			$value = $filter->filter($value);
		}
		
		return $value;
	}
	
	/**
	 * @param Filter $filter
	 *
	 * @return self
	 */
	public function addFilter(Filter $filter): self
	{
		$this->_filters[] = $filter;

		return $this;
	}

	/**
	 * @param Validator $validator
	 *
	 * @return self
	 */
	public function addValidator(Validator $validator): self
	{
		$this->_validators[] = $validator;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isValid(): bool
	{
		$this->_errors = []; // reset errors
		$value = $this->getValue();
		
		foreach($this->_validators as $validator)
		{
			$validator->setElement($this);
			if($validator->isValid($value) === false)
			{
				$this->addErrors($validator->getErrors());
			}
		}

		return $this->hasErrors() === false;
	}

	/**
	 * @param Error $error
	 *
	 * @return self
	 */
	public function addError(Error $error): self
	{
		$error->setElement($this);
		$this->_errors[] = $error;

		return $this;
	}

	/**
	 * @param Error[] $errors
	 *
	 * @return self
	 */
	public function addErrors(array $errors): self
	{
		foreach($errors as $error)
		{
			$this->addError($error);
		}
		
		return $this;
	}

	/**
	 * @return bool
	 */
	public function hasErrors(): bool
	{
		return count($this->_errors) > 0;
	}
	
	/**
	 * @return self
	 */
	public function clearErrors(): self
	{
		$this->_errors = [];
		
		return $this;
	}

	/**
	 * @return Error[]
	 */
	public function getErrors(): array
	{
		return $this->_errors;
	}

	/**
	 * @return string
	 */
	public function __toString(): string
	{
		return (string)$this->getValue();
	}
}
