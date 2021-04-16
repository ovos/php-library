<?php
declare(strict_types=1);

namespace Ovos\View\Helper;

use Ovos\View\Helper;
use Ovos\Form\Element;
use Ovos\View;
use function sprintf;

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
	protected null|Element $_element = null;

	/**
	 * @var null|string
	 */
	protected null|string $_type = null;

	/**
	 * @var null|string
	 */
	protected null|string $_description = null;

	/**
	 * @var null|string
	 */
	protected null|string $_placeholder = null;

	/**
	 * @var null|string
	 */
	protected null|string $_elementClass = null;

	/**
	 * @var null|string
	 */
	protected null|string $_fieldClass = null;

	/**
	 * @var array
	 */
	protected array $_attributes = [];

	/**
	 * @var null|string
	 */
	protected null|string $_optionWrap = null;

	/**
	 * @var array
	 */
	protected array $_options = [];

	/**
	 * @param Element $element
	 * 
	 * @return self
	 */
	public function formElement(Element $element): self
	{
		return new self($element);
	}

	/**
	 * @param null|Element $element
	 */
	public function __construct(null|Element $element = null)
	{
		parent::__construct();
		
		$this->setElement($element);
	}

	/**
	 * @param null|Element $element
	 * 
	 * @return self;
	 */
	public function setElement(null|Element $element): self
	{
		$this->_element = $element;
		
		return $this;
	}

	/**
	 * @return null|Element
	 */
	public function getElement(): null|Element
	{
		return $this->_element;
	}

	/**
	 * @param null|string $type
	 * 
	 * @return self
	 */
	public function setType(null|string $type): self
	{
		$this->_type = $type;
		
		return $this;
	}

	/**
	 * @return null|string
	 */
	public function getType(): null|string
	{
		return $this->_type;
	}

	/**
	 * @param null|string $description
	 * 
	 * @return self
	 */
	public function setDescription(null|string $description): self
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
	 * @return self
	 */
	public function setPlaceholder(null|string $placeholder): self
	{
		$this->_placeholder = $placeholder;
		
		return $this;
	}

	/**
	 * @return null|string
	 */
	public function getPlaceholder(): null|string
	{
		return $this->_placeholder;
	}

	/**
	 * @param null|string $elementClass
	 * 
	 * @return self
	 */
	public function setElementClass(null|string $elementClass): self
	{
		$this->_elementClass = $elementClass;
		
		return $this;
	}

	/**
	 * @return null|string
	 */
	public function getElementClass(): null|string
	{
		return $this->_elementClass;
	}

	/**
	 * @param null|string $fieldClass
	 * 
	 * @return self
	 */
	public function setFieldClass(null|string $fieldClass): self
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
	 * @return self
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
	 * @return self
	 */
	public function setOptionWrap(null|string $optionWrap): self
	{
		$this->_optionWrap = $optionWrap;
		
		return $this;
	}

	/**
	 * @return null|string
	 */
	public function getOptionWrap(): null|string
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
	 * @return self
	 */
	public function setOption(string $key, mixed $value): self
	{
		$this->_options[$key] = $value;
		
		return $this;
	}

	/**
	 * @param string $key
	 * 
	 * @return mixed
	 */
	public function getOption(string $key): mixed
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
