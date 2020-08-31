<?php
declare(strict_types=1);

namespace Ovos\View\Helper;

use Ovos\View\Helper;
use Ovos\Form\Element;
use Ovos\View;

/**
 * FormElement
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class FormElement extends Helper
{
	/**
	 * @var null|Element
	 */
	protected $_element;

	/**
	 * @var null|string
	 */
	protected $_type;

	/**
	 * @var null|string
	 */
	protected $_description;

	/**
	 * @var null|string
	 */
	protected $_placeholder;

	/**
	 * @var null|string
	 */
	protected $_elementClass;

	/**
	 * @var null|string
	 */
	protected $_fieldClass;

	/**
	 * @var array
	 */
	protected $_attributes = [];

	/**
	 * @var null|string
	 */
	protected $_optionWrap;

	/**
	 * @var array
	 */
	protected $_options = [];

	/**
	 * @param Element $element
	 * 
	 * @return $this
	 */
	public function formElement(Element $element): self
	{
		return new self($element);
	}

	/**
	 * @param Element $element
	 */
	public function __construct(Element $element = null)
	{
		parent::__construct();
		
		$this->setElement($element);
	}

	/**
	 * @param null|Element $element
	 * 
	 * @return $this;
	 */
	public function setElement(?Element $element): self
	{
		$this->_element = $element;
		
		return $this;
	}

	/**
	 * @return null|Element
	 */
	public function getElement(): ?Element
	{
		return $this->_element;
	}

	/**
	 * @param null|string $type
	 * 
	 * @return $this
	 */
	public function setType(?string $type): self
	{
		$this->_type = $type;
		
		return $this;
	}

	/**
	 * @return null|string
	 */
	public function getType(): ?string
	{
		return $this->_type;
	}

	/**
	 * @param null|string $description
	 * 
	 * @return $this
	 */
	public function setDescription(?string $description): self
	{
		$this->_description = $description;
		
		return $this;
	}

	/**
	 * @return null|string
	 */
	public function getDescription(): ?string
	{
		return $this->_description;
	}

	/**
	 * @param null|string $placeholder
	 * 
	 * @return $this
	 */
	public function setPlaceholder(?string $placeholder): self
	{
		$this->_placeholder = $placeholder;
		
		return $this;
	}

	/**
	 * @return null|string
	 */
	public function getPlaceholder(): ?string
	{
		return $this->_placeholder;
	}

	/**
	 * @param null|string $elementClass
	 * 
	 * @return $this
	 */
	public function setElementClass(?string $elementClass): self
	{
		$this->_elementClass = $elementClass;
		
		return $this;
	}

	/**
	 * @return null|string
	 */
	public function getElementClass(): ?string
	{
		return $this->_elementClass;
	}

	/**
	 * @param null|string $fieldClass
	 * 
	 * @return $this
	 */
	public function setFieldClass(?string $fieldClass): self
	{
		$this->_fieldClass = $fieldClass;
		
		return $this;
	}

	/**
	 * @return null|string
	 */
	public function getFieldClass(): ?string
	{
		return $this->_fieldClass;
	}

	/**
	 * @param array $attributes
	 * 
	 * @return $this
	 */
	public function setAttributes(array $attributes): self
	{
		$this->_attributes = $attributes;
		
		return $this;
	}

	/**
	 * @return array
	 */
	public function getAttributes(): array
	{
		return $this->_attributes;
	}

	/**
	 * @param null|string $optionWrap
	 * 
	 * @return $this
	 */
	public function setOptionWrap(?string $optionWrap): self
	{
		$this->_optionWrap = $optionWrap;
		
		return $this;
	}

	/**
	 * @return null|string
	 */
	public function getOptionWrap(): ?string
	{
		return $this->_optionWrap;
	}

	/**
	 * @param string $label
	 * 
	 * @return string
	 */
	public function optionWrap(string $label): string
	{
		if($this->_optionWrap === null)
		{
			return $label;
		}
	
		return sprintf($this->_optionWrap, $label);
	}

	/**
	 * @param string $key
	 * @param mixed $value
	 * 
	 * @return $this
	 */
	public function setOption(string $key, $value): self
	{
		$this->_options[$key] = $value;
		
		return $this;
	}

	/**
	 * @param string $key
	 * 
	 * @return null|mixed
	 */
	public function getOption(string $key)
	{
		return $this->_options[$key] ?? null;
	}
	
	/**
	 * @param Element $element
	 * 
	 * @return string
	 */
	public function render(Element $element): string
	{
		$view = new View('helpers/form-element.phtml');
		$view->helper = $this;
		$view->element = $element;
		
		return $view->render();
	}

	/**
	 * @return string
	 */
	public function __toString(): string
	{
		return $this->render($this->_element);
	}
}
