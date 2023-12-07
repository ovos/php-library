<?php
declare(strict_types=1);

namespace Ovos\View\Helper;

use Closure;
use Ovos\View\Helper;
use Ovos\Form\Element;
use Ovos\Form\Element\Options\Option;
use Ovos\View;
use Ovos\View\Helper\Placeholders\Placeholder;
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
	 * @var ?Element
	 */
	protected ?Element $_element = null;

	/**
	 * @var ?string
	 */
	protected ?string $_type = null;

	/**
	 * @var ?string
	 */
	protected ?string $_description = null;

	/**
	 * @var ?string
	 */
	protected ?string $_placeholder = null;

	/**
	 * @var ?string
	 */
	protected ?string $_elementClass = null;

	/**
	 * @var ?string
	 */
	protected ?string $_fieldClass = null;

	/**
	 * @var ?Closure
	 */
	protected ?Closure $_fieldClassCallback = null;

	/**
	 * @var ?Closure
	 */
	protected ?Closure $_fieldLabelCallback = null;

	/**
	 * @var ?Placeholder
	 */
	protected ?Placeholder $_labelInsert = null;

	/**
	 * @var ?Placeholder
	 */
	protected ?Placeholder $_fieldInsert = null;
	
	/**
	 * @var ?Placeholder
	 */
	protected ?Placeholder $_inputInsert = null;

	/**
	 * @var array
	 */
	protected array $_attributes = [];

	/**
	 * @var Closure
	 */
	protected ?Closure $_attributesCallback = null;

	/**
	 * @var array
	 */
	protected array $_options = [];

	/**
	 * @var ?string
	 */
	protected ?string $_optionLabelWrap = null;

	/**
	 * @var ?Closure
	 */
	protected ?Closure $_optionCallback = null;
	
	/**
	 * @var ?Closure
	 */
	protected ?Closure $_optionLabelCallback = null;

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
	 * @param ?Element $element
	 */
	public function __construct(?Element $element = null)
	{
		parent::__construct();
		
		$this->setElement($element);
	}

	/**
	 * @param ?Element $element
	 * 
	 * @return self
	 */
	public function setElement(?Element $element): self
	{
		$this->_element = $element;
		
		return $this;
	}

	/**
	 * @return ?Element
	 */
	public function getElement(): ?Element
	{
		return $this->_element;
	}

	/**
	 * @param ?string $type
	 * 
	 * @return self
	 */
	public function setType(?string $type): self
	{
		$this->_type = $type;
		
		return $this;
	}

	/**
	 * @return ?string
	 */
	public function getType(): null|string
	{
		return $this->_type;
	}

	/**
	 * @param ?Placeholder $insert
	 * 
	 * @return self
	 */
	public function setLabelInsert(?Placeholder $insert): self
	{
		$this->_labelInsert = $insert;
		
		return $this;
	}

	/**
	 * @return ?Placeholder
	 */
	public function getLabelInsert(): ?Placeholder
	{
		return $this->_labelInsert;
	}
	
	/**
	 * @param ?Placeholder $insert
	 * 
	 * @return self
	 */
	public function setFieldInsert(?Placeholder $insert): self
	{
		$this->_fieldInsert = $insert;
		
		return $this;
	}
	
	/**
	 * @return ?Placeholder
	 */
	public function getFieldInsert(): ?Placeholder
	{
		return $this->_fieldInsert;
	}
		
	/**
	 * @param ?Placeholder $insert
	 * 
	 * @return self
	 */
	public function setInputInsert(?Placeholder $insert): self
	{
		$this->_inputInsert = $insert;
		
		return $this;
	}
	
	/**
	 * @return ?Placeholder
	 */
	public function getInputInsert(): ?Placeholder
	{
		return $this->_inputInsert;
	}
	
	/**
	 * @param ?string $description
	 * 
	 * @return self
	 */
	public function setDescription(?string $description): self
	{
		$this->_description = $description;
		
		return $this;
	}

	/**
	 * @return ?string
	 */
	public function getDescription(): ?string
	{
		return $this->_description;
	}

	/**
	 * @param ?string $placeholder
	 * 
	 * @return self
	 */
	public function setPlaceholder(?string $placeholder): self
	{
		$this->_placeholder = $placeholder;
		
		return $this;
	}

	/**
	 * @return ?string
	 */
	public function getPlaceholder(): null|string
	{
		return $this->_placeholder;
	}

	/**
	 * @param ?string $elementClass
	 * 
	 * @return self
	 */
	public function setElementClass(?string $elementClass): self
	{
		$this->_elementClass = $elementClass;
		
		return $this;
	}

	/**
	 * @return ?string
	 */
	public function getElementClass(): null|string
	{
		return $this->_elementClass;
	}

	/**
	 * @param ?string $fieldClass
	 * 
	 * @return self
	 */
	public function setFieldClass(?string $fieldClass): self
	{
		$this->_fieldClass = $fieldClass;
		
		return $this;
	}

	/**
	 * @return ?string
	 */
	public function getFieldClass(): ?string
	{
		return $this->_fieldClass;
	}
	
	/**
	 * @param ?Closure $fieldClassCallback
	 * 
	 * @return self
	 */
	public function setFieldClassCallback(?Closure $fieldClassCallback): self
	{
		$this->_fieldClassCallback = $fieldClassCallback;
		
		return $this;
	}

	/**
	 * @return ?Closure
	 */
	public function getFieldClassCallback(): ?Closure
	{
		return $this->_fieldClassCallback;
	}
	
	/**
	 * @param ?Closure $fieldLabelCallback
	 * 
	 * @return self
	 */
	public function setFieldLabelCallback(?Closure $fieldLabelCallback): self
	{
		$this->_fieldLabelCallback = $fieldLabelCallback;
		
		return $this;
	}

	/**
	 * @return ?Closure
	 */
	public function getFieldLabelCallback(): ?Closure
	{
		return $this->_fieldLabelCallback;
	}
	
	/**
	 * @param Option $option
	 * 
	 * @return string
	 */
	public function fieldLabelCallback(Option $option): string
	{
		if($this->_fieldLabelCallback === null)
		{
			return $option->getLabel();
		}
	
		return ($this->_fieldLabelCallback)($option);
	}	

	/**
	 * @param string $attribute
	 * @param int|string $value
	 *
	 * @return self
	 */
	public function setAttribute(string $attribute, int|string $value): self
	{
		$this->_attributes[$attribute] = $value;
		
		return $this;
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
	 * @param ?Closure $attributesCallback
	 * 
	 * @return self
	 */
	public function setAttributesCallback(?Closure $attributesCallback): self
	{
		$this->_attributesCallback = $attributesCallback;
		
		return $this;
	}

	/**
	 * @return ?Closure
	 */
	public function getAttributesCallback(): ?Closure
	{
		return $this->_attributesCallback;
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
	 * @deprecated
	 * @see setOptionLabelWrap 
	 * 
	 * @param ?string $optionWrap
	 * 
	 * @return self
	 */
	public function setOptionWrap(?string $optionWrap): self
	{
		return $this->setOptionLabelWrap($optionWrap);
	}
	
	/**
	 * @param ?string $optionLabelWrap
	 * 
	 * @return self
	 */
	public function setOptionLabelWrap(?string $optionLabelWrap): self
	{
		$this->_optionLabelWrap = $optionLabelWrap;
		
		return $this;
	}

	/**
	 * @deprecated
	 * @see getOptionLabelWrap
	 * 
	 * @return ?string
	 */
	public function getOptionWrap(): null|string
	{
		return $this->getOptionLabelWrap();
	}

	/**
	 * @return ?string
	 */
	public function getOptionLabelWrap(): null|string
	{
		return $this->_optionLabelWrap;
	}

	/**
	 * @deprecated
	 * @see optionLabelWrap
	 * 
	 * @param string $label
	 * 
	 * @return string
	 */
	public function optionWrap(string $label): string
	{
		return $this->optionLabelWrap($label);
	}
	
	/**
	 * @param string $label
	 * 
	 * @return string
	 */
	public function optionLabelWrap(string $label): string
	{
		if($this->_optionLabelWrap === null)
		{
			return $label;
		}
	
		return sprintf($this->_optionLabelWrap, $label);
	}
	
	/**
	 * @param ?Closure $optionLabelCallback
	 * 
	 * @return self
	 */
	public function setOptionLabelCallback(?Closure $optionLabelCallback): self
	{
		$this->_optionLabelCallback = $optionLabelCallback;
		
		return $this;
	}
	
	/**
	 * @return ?Closure
	 */
	public function getOptionLabelCallback(): ?Closure
	{
		return $this->_optionLabelCallback;
	}
	
	/**
	 * @param string $label
	 * 
	 * @return string
	 */
	public function optionLabelCallback(string $label): string
	{
		if($this->_optionLabelCallback === null)
		{
			return $label;
		}
	
		return ($this->_optionLabelCallback)($label);
	}
		
	/**
	 * Universal callback, which allows to call any FormElement method
	 * 
	 * @param ?Closure $optionCallback
	 * 
	 * @return self
	 */
	public function setOptionCallback(?Closure $optionCallback): self
	{
		$this->_optionCallback = $optionCallback;
		
		return $this;
	}
	
	/**
	 * @return ?Closure
	 */
	public function getOptionCallback(): ?Closure
	{
		return $this->_optionCallback;
	}
	
	/**
	 * @param Option $option
	 * 
	 * @return self
	 */
	public function optionCallback(Option $option): self
	{
		if($this->_optionCallback !== null)
		{
			($this->_optionCallback)($this, $option);
		}
		
		return $this;
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
