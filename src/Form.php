<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Form\Element;
use Ovos\Form\Error;
use Iterator;

use function array_key_exists;
use function array_merge;
use function current;
use function key;
use function next;
use function reset;

/**
 * Form
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Form implements Iterator
{
	use Translatable;
	
	protected Application $app;
	
	protected ?string $id = null;
	
	/**
	 * Parent form
	 */
	protected ?Form $form = null;
	
	protected Url $action;
	
	/**
	 * Element and Form objects, ordered
	 *
	 * @var Element[]|Form[]
	 */
	protected array $elements = [];
	
	protected array $values = [];
	
	/**
	 * @var string[]
	 */
	protected array $defaults = [];
	
	public function __construct(
		?string $id = null,
	)
	{
		$this->app = app();
		$this->id = $id;
		
		$this->init();
	}
	
	public function init(): void
	{
	}
	
	public function reset(): static
	{
		$this->values = [];
		
		foreach($this->elements as $element)
		{
			$element->reset();
		}
		
		return $this;
	}
	
	public function setId(
		?string $id,
	): static
	{
		$this->id = $id;
		
		return $this;
	}
	
	public function getId(): ?string
	{
		$id = $this->id;
		if($this->form !== null)
		{
			$id = $this->form->getId() . '_' . $id;
		}
		
		return $id;
	}
	
	public function setForm(
		Form $form,
	): static
	{
		$this->form = $form;
		
		return $this;
	}
	
	public function setAction(
		Url $action,
	): static
	{
		$this->action = $action;
		
		return $this;
	}
	
	public function getAction(): ?Url
	{
		return $this->action;
	}
	
	public function setValue(
		string $id,
		null|string|bool|int|float|array $value,
	): static
	{
		$this->values[$id] = $value;
		
		return $this;
	}
	
	public function setValues(
		array $values,
	): static
	{
		if($this->id !== null && isset($values[$this->id]))
		{
			$values = $values[$this->id];
		}
		
		$this->values = array_merge($this->values, $values);
		
		return $this;
	}
	
	/**
	 * Raw value (unfiltered)
	 */
	public function getRawValue(
		string $id,
	): null|string|bool|int|float|array
	{
		if(isset($this->values[$id]))
		{
			return $this->values[$id];
		}
		
		return null;
	}
	
	/**
	 * Raw values
	 */
	public function getRawValues(): array
	{
		return $this->values;
	}
	
	/**
	 * Set default value
	 */
	public function setDefault(
		string $id,
		null|string|bool|int|float|array $default,
	): static
	{
		$this->defaults[$id] = $default;
		
		return $this;
	}
	
	/**
	 * Set default values
	 */
	public function setDefaults(
		array $defaults,
	): static
	{
		if($this->id !== null && isset($defaults[$this->id]))
		{
			$defaults = $defaults[$this->id];
		}
		
		$this->defaults = array_merge($this->defaults, $defaults);
		
		return $this;
	}
	
	/**
	 * Default value
	 */
	public function getDefaultValue(
		string $id,
	): null|string|bool|int|float|array
	{
		if(isset($this->defaults[$id]))
		{
			return $this->defaults[$id];
		}
		
		return null;
	}
	
	/**
	 * Default values
	 */
	public function getDefaultValues(): array
	{
		return $this->defaults;
	}
	
	/**
	 * Value (filtered)
	 */
	public function getValue(
		string $id,
		bool $default = false,
	): null|string|bool|int|float|array
	{
		return $this->__get($id)->getValue($default);
	}
	
	/**
	 * Input value (filtered)
	 */
	public function getInputValue(
		string $id,
	): null|string|bool|int|float|array
	{
		return $this->__get($id)->getValue(true);
	}
	
	/**
	 * User value (filtered)
	 */
	public function getUserValue(
		string $id,
	): null|string|bool|int|float|array
	{
		return $this->__get($id)->getValue(false);
	}
	
	/**
	 * Validated and filtered values
	 */
	public function getValues(
		bool $default = false,
	): array
	{
		$values = [];
		
		foreach($this->elements as $element)
		{
			$values[$element->getId(withFormId: false)] 
				= $element->getValue($default);
		}
		
		return $values;
	}
	
	/**
	 * Validated and filtered input values
	 */
	public function getInputValues(): array
	{
		return $this->getValues(true);
	}
	
	/**
	 * Validated and filtered user values
	 */
	public function getUserValues(): array
	{
		return $this->getValues(false);
	}
	
	public function __get(
		string $id,
	): Element
	{
		if($this->__isset($id) === false)
		{
			$element = new Element;
			$this->__set($id, $element); // default type
		}
		
		return $this->elements[$id];
	}
	
	public function getElement(
		string $id,
	): Element
	{
		return $this->__get($id);
	}
	
	public function __set(
		string $id,
		Element $element,
	): void
	{
		$element->setForm($this);
		$element->setId($id);
		
		$this->elements[$id] = $element;
	}
	
	public function setElement(
		string $id,
		Element $element,
	): static
	{
		$this->__set($id, $element);
		
		return $this;
	}
	
	public function __isset(
		string $id,
	): bool
	{
		return array_key_exists($id, $this->elements);
	}
	
	public function hasElement(
		string $id,
	): bool
	{
		return $this->__isset($id);
	}
	
	public function isValid(): bool
	{
		$isValid = true;
		
		foreach($this->elements as $element)
		{
			$isValid = $isValid
				&& $element->isValid();
		}
		
		return $isValid;
	}
	
	/**
	 * @return Error[]
	 */
	public function getErrors(): array
	{
		$errors = [[]];
		
		foreach($this->elements as $element)
		{
			$errors[] = $element->getErrors();
		}
		
		return array_merge(...$errors);
	}
	
	public function getForm(): Form
	{
		return $this->form;
	}
	
	/**
	 * @return Element[]|Form[]
	 */
	public function toArray(): array
	{
		return $this->elements;
	}
	
	public function rewind(): void
	{
		reset($this->elements);
	}
	
	public function current(
	): Form|Element|false
	{
		return current($this->elements);
	}
	
	public function next(): void
	{
		next($this->elements);
	}
	
	public function key(): mixed
	{
		return key($this->elements);
	}
	
	public function valid(): bool
	{
		$key = $this->key();
		
		return ($key !== null && $key !== false);
	}
}
