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
	protected ?Closure $_labelCallback = null;
	
	/**
	 * @var ?string
	 */
	protected ?string $_labelClass = null;
	
	/**
	 * @var ?Closure
	 */
	protected ?Closure $_labelClassCallback = null;
	
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
	 * @var ?Closure
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
	 * @var ?Closure
	 */
	protected ?Closure $_optionClassCallback = null;
	
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
	
	/* Element */
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
	
	/* Type */
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
	public function getType(): ?string
	{
		return $this->_type;
	}
	
	/* Label insert */
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
	
	/* Field insert */
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
	
	/* Input insert */
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
	
	/* Description */
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
	
	/* Placeholder */
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
	public function getPlaceholder(): ?string
	{
		return $this->_placeholder;
	}
	
	/* Element class */
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
	public function getElementClass(): ?string
	{
		return $this->_elementClass;
	}
	
	/* Field class */
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
	
	/* Label callback */
	/**
	 * @param ?Closure $labelCallback
	 * 
	 * @return self
	 */
	public function setLabelCallback(?Closure $labelCallback): self
	{
		$this->_labelCallback = $labelCallback;
		
		return $this;
	}
	
	/**
	 * @return ?Closure
	 */
	public function getLabelCallback(): ?Closure
	{
		return $this->_labelCallback;
	}
	
	/**
	 * @param Option $option
	 * 
	 * @return string
	 */
	public function labelCallback(Option $option): string
	{
		if($this->_labelCallback === null)
		{
			return $option->getLabel();
		}
		
		return ($this->_labelCallback)($option);
	}
	
	/* Label class */
	/**
	 * @param ?string $labelClass
	 * 
	 * @return self
	 */
	public function setLabelClass(?string $labelClass): self
	{
		$this->_labelClass = $labelClass;
		
		return $this;
	}
	
	/**
	 * @return ?string
	 */
	public function getLabelClass(): ?string
	{
		return $this->_labelClass;
	}
	
	/* Label class callback */
	/**
	 * @param ?Closure $labelClassCallback
	 * 
	 * @return self
	 */
	public function setLabelClassCallback(?Closure $labelClassCallback): self
	{
		$this->_labelClassCallback = $labelClassCallback;
		
		return $this;
	}
	
	/**
	 * @return ?Closure
	 */
	public function getLabelClassCallback(): ?Closure
	{
		return $this->_labelClassCallback;
	}
	
	/**
	 * @return ?string
	 */
	public function labelClassCallback(): ?string
	{
		if($this->_labelClassCallback === null)
		{
			return null;
		}
		
		return ($this->_labelClassCallback)();
	}
	
	/* Attributes */
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
	
	/* Attributes callback */
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
	
	/* Option */
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
	
	/* Option callback */
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
	
	/* Option wrap */
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
	public function getOptionWrap(): ?string
	{
		return $this->getOptionLabelWrap();
	}
	
	/**
	 * @return ?string
	 */
	public function getOptionLabelWrap(): ?string
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
	
	/* Option label callback */
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
	 * @param Option $option
	 * 
	 * @return string
	 */
	public function optionLabelCallback(Option $option): string
	{
		if($this->_optionLabelCallback === null)
		{
			return $option->getLabel();
		}
		
		return ($this->_optionLabelCallback)($option);
	}
	
	/* Option class callback */
	/**
	 * @param ?Closure $optionClassCallback
	 * 
	 * @return self
	 */
	public function setOptionClassCallback(?Closure $optionClassCallback): self
	{
		$this->_optionClassCallback = $optionClassCallback;
		
		return $this;
	}
	
	/**
	 * @return ?Closure
	 */
	public function getOptionClassCallback(): ?Closure
	{
		return $this->_optionClassCallback;
	}
	
	/**
	 * @param Option $option
	 * 
	 * @return ?string
	 */
	public function optionClassCallback(Option $option): ?string
	{
		if($this->_optionClassCallback === null)
		{
			return null;
		}
		
		return ($this->_optionClassCallback)($option);
	}
	
	/**
	 * @deprecated
	 * @see setOptionLabelCallback()
	 * 
	 * @param ?Closure $labelCallback
	 * 
	 * @return self
	 */
	public function setFieldLabelCallback(?Closure $labelCallback): self
	{
		return $this->setOptionLabelCallback($labelCallback);
	}
	
	/**
	 * @deprecated
	 * @see getOptionLabelCallback()
	 * 
	 * @return ?Closure
	 */
	public function getFieldLabelCallback(): ?Closure
	{
		return $this->getOptionLabelCallback();
	}
	
	/**
	 * @deprecated
	 * @see optionLabelCallback()
	 * 
	 * @param Option $option
	 * 
	 * @return string
	 */
	public function fieldLabelCallback(Option $option): string
	{
		return $this->optionLabelCallback($option);
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
