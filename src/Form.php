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
	
	protected ?string $id = null;
	
	/**
	 * Parent form
	 */
	protected ?Form $form = null;
	
	protected Url $action;
	
	/**
	 * The elements, ordered — and only elements: a nested form relates
	 * through setForm(), which drives the id/name prefixing of rendered
	 * fields, and is fed and read directly; it never sits among the
	 * parent's elements (__set() is typed accordingly).
	 *
	 * @var Element[]
	 */
	protected array $elements = [];
	
	protected array $values = [];
	
	/**
	 * @var string[]
	 */
	protected array $defaults = [];
	
	/**
	 * No app() here. It set a protected $app that nothing ever read — not this
	 * class, not Translatable (which goes through Translator:: statically), not
	 * one of the twenty-two form subclasses in a large application of ours — and its only real effect
	 * was to make a form unconstructible outside a booted application. A form
	 * is named values, filters and validators; none of that needs the
	 * application, and now a unit test or a CLI script can build one.
	 */
	public function __construct(
		?string $id = null,
	)
	{
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
	 * Raw value (unfiltered) — null both when the key holds null and when
	 * it is absent: a return value cannot carry the difference, that is
	 * hasValue()'s job
	 */
	public function getRawValue(
		string $id,
	): null|string|bool|int|float|array
	{
		if(array_key_exists($id, $this->values))
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
	 * Whether this field is present in the fed values AT ALL — whatever
	 * setValues() was given: an HTML submission, a request body, a database
	 * row. A browser cannot produce a present null, but data can, so:
	 * array_key_exists, not isset — an explicit null is a value, and the
	 * difference between "clear it" and "leave it" is exactly what a
	 * partial update turns on.
	 */
	public function hasValue(
		string $id,
	): bool
	{
		return array_key_exists($id, $this->values);
	}
	
	/**
	 * Treat these fields as present even when they are not — for a CREATE,
	 * where "absent" cannot mean "keep the stored value" because there is
	 * nothing stored to keep.
	 *
	 * An absent id becomes an explicit null, so the element's own validators
	 * produce the proper field error (NotEmpty says "name cannot be empty",
	 * a non-nullable Range says what the bounds are) instead of the save
	 * dying on a NOT NULL column. The caller applies this only on create;
	 * the form cannot know a create from an update.
	 */
	public function requireValues(
		string ...$ids,
	): static
	{
		foreach($ids as $id)
		{
			if($this->hasValue($id) === false)
			{
				$this->setValue($id, null);
			}
		}
		
		return $this;
	}
	
	/**
	 * Only the fields actually present, filtered, validated and CAST — the
	 * storage form. getInputValues() answers for EVERY declared element
	 * (defaults included), which is what you want when writing a whole
	 * record; this is what you want when writing a partial one and the
	 * storage layer treats "present" as "assign".
	 */
	public function getPresentValues(): array
	{
		$values = [];
		
		foreach($this->elements as $element)
		{
			$id = $element->getId(withFormId: false);
			if($this->hasValue($id))
			{
				// the CAST value — the storage form. Casts run only here,
				// after validation saw the uncast value; the HTML accessors
				// (getValues/getInputValues) stay cast-free.
				$values[$id] = $element->getCastValue();
			}
		}
		
		return $values;
	}
	
	/**
	 * field => first message, the shape an API error body wants.
	 *
	 * First and not all of them: a field with three broken rules is still one
	 * broken field to the caller, and the UI draws one line under one input.
	 *
	 * @return array<string, string>
	 */
	public function getErrorMessages(): array
	{
		$messages = [];
		
		foreach($this->getErrors() as $error)
		{
			$element = $error->getElement();
			$id = $element === null ? '' : $element->getId(withFormId: false);
			
			if($id !== '' && array_key_exists($id, $messages) === false)
			{
				$messages[$id] = (string)$error->getMessage();
			}
		}
		
		return $messages;
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
			// every element, not `$isValid && $element->isValid()` — && SHORT
			// CIRCUITS, so once one field had failed none of the elements after
			// it were validated at all. The form reported the FIRST broken
			// field and nothing else: the user fixed it, submitted, and met the
			// next one, one round trip per mistake. getErrors() was equally
			// short, so an API answering with them listed one of five.
			if($element->isValid() === false)
			{
				$isValid = false;
			}
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
	 * @return Element[]
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
