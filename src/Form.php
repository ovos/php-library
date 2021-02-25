<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Form\Element;
use Ovos\Form\Error;
use Iterator;

/**
 * Form
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Form implements Iterator
{
	use Translatable;
	
	/**
	 * @var Application
	 */
	protected Application $_app;

	/**
	 * @var string
	 */
	protected null|string $_id = null;

	/**
	 * Parent form
	 *
	 * @var Form
	 */
	protected Form $_form;

	/**
	 * @var Url
	 */
	protected Url $_action;

	/**
	 * Element and Form objects, ordered
	 *
	 * @var Element[]|Form[]
	 */
	protected array $_elements = [];

	/**
	 * @var array
	 */
	protected array $_values = [];
	
	/**
	 * @var string[]
	 */
	protected array $_defaults = [];

	/**
	 * @param string $id
	 */
	public function __construct(string $id = null)
	{
		$this->_app = app();
		$this->_id = $id;

		$this->init();
	}

	/**
	 */
	public function init(): void
	{
	}

	/**
	 * @return null|string
	 */
	public function getId(): ?string
	{
		$id = $this->_id;
		if($this->_form)
		{
			$id = $this->_form->getId() . '_' . $id;
		}

		return $id;
	}

	/**
	 * @param Form $form
	 *
	 * @return $this
	 */
	public function setForm(Form $form): self
	{
		$this->_form = $form;

		return $this;
	}

	/**
	 * @param Url $action
	 * 
	 * @return $this
	 */
	public function setAction(Url $action): self
	{
		$this->_action = $action;
		
		return $this;
	}

	/**
	 * @return null|Url
	 */
	public function getAction(): ?Url
	{
		return $this->_action;
	}

	/**
	 * @param array $values
	 *
	 * @return $this
	 */
	public function setValues(array $values): self
	{
		if($this->_id !== null && isset($values[$this->_id]))
		{
			$values = $values[$this->_id];
		}

		$this->_values = $values;

		return $this;
	}

	/**
	 * @param string $id
	 * @param null|string|int|float|array $value
	 * 
	 * @return $this
	 */
	public function setValue(string $id, $value): self
	{
		$this->_values[$id] = $value;
	
		return $this;
	}
	
	/**
	 * Raw input value
	 * 
	 * @param string $id
	 *
	 * @return null|string|int|float|array
	 */
	public function getValue(string $id)
	{
		if(isset($this->_values[$id]))
		{
			return $this->_values[$id];
		}

		if(isset($this->_defaults[$id]))
		{
			return $this->_defaults[$id];
		}

		return null;
	}

	/**
	 * Raw input values
	 * 
	 * @return array
	 */
	public function getValues(): array
	{
		return $this->_values;
	}
	
	/**
	 * @return array
	 */
	public function getValuesFiltered(): array
	{
		$values = [];
		
		foreach($this->_elements as $element)
		{
			$values[$element->getId()] = $element->getValue();
		}
	
		return $values;
	}
	
	/**
	 * @param array $defaults
	 *
	 * @return $this
	 */
	public function setDefaults(array $defaults): self
	{
		if($this->_id !== null && isset($defaults[$this->_id]))
		{
			$defaults = $defaults[$this->_id];
		}

		$this->_defaults = $defaults;

		return $this;
	}	

	/**
	 * @param string $id
	 * @param null|string|int|float|array $default
	 * 
	 * @return $this
	 */
	public function setDefault(string $id, $default): self
	{
		$this->_defaults[$id] = $default;
	
		return $this;
	}

	/**
	 * @param string $id
	 *
	 * @return Element
	 */
	public function __get($id)
	{
		if($this->__isset($id) === false)
		{
			$element = new Element;
			$this->__set($id, $element); // default type
		}

		return $this->_elements[$id];
	}
	
	/**
	 * @param string $id
	 *
	 * @return Element
	 */
	public function getElement(string $id)
	{
		return $this->__get($id);
	}

	/**
	 * @param string $id
	 * @param Element $element
	 */
	public function __set(string $id, Element $element): void
	{
		$element->setForm($this);
		$element->setId($id);
		
		$this->_elements[$id] = $element;
	}

	/**
	 * @param string $id
	 * @param Element $element
	 * 
	 * @return $this
	 */
	public function setElement(string $id, Element $element): self
	{
		$this->__set($id, $element);
		
		return $this;
	}

	/**
	 * @param string $id
	 * 
	 * @return bool
	 */
	public function __isset(string $id): bool
	{
		return array_key_exists($id, $this->_elements);
	}
	
	/**
	 * @param string $id
	 * 
	 * @return bool
	 */
	public function hasElement(string $id): bool
	{
		return $this->__isset($id);
	}

	/**
	 * @return bool
	 */
	public function isValid(): bool
	{
		foreach($this->_elements as $element)
		{
			$element->isValid();
		}
	
		return count($this->getErrors()) === 0;
	}

	/**
	 * @return Error[]
	 */
	public function getErrors(): array
	{
		$errors = [[]];

		foreach($this->_elements as $element)
		{
			$errors[] = $element->getErrors();
		}
		
		return array_merge(...$errors);
	}

	/**
	 * @return Form
	 */
	public function getForm(): Form
	{
		return $this->_form;
	}

	/**
	 * @return Element[]|Form[]
	 */
	public function toArray(): array
	{
		return $this->_elements;
	}

	/**
	 * @return void
	 */
	public function rewind(): void
	{
		reset($this->_elements);
	}

	/**
	 * @return mixed
	 */
	public function current(): mixed
	{
		return current($this->_elements);
	}

	/**
	 * @return void
	 */
	public function next(): void
	{
		next($this->_elements);
	}

	/**
	 * @return int|mixed|null|string
	 */
	public function key(): mixed
	{
		return key($this->_elements);
	}

	/**
	 * @return bool
	 */
	public function valid(): bool
	{
		$key = $this->key();

		return ($key !== null && $key !== false);
	}
}
