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
	protected array $_inputAttributes = [];
	
	/**
	 * @var ?Closure
	 */
	protected ?Closure $_inputAttributesCallback = null;
	
	/**
	 * @var array
	 */
	protected array $_optionAttributes = [];
	
	/**
	 * @var ?Closure
	 */
	protected ?Closure $_optionAttributesCallback = null;
	
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
	 * @return static
	 */
	public function formElement(Element $element): static
	{
		return new static($element);
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
	 * @return static
	 */
	public function setElement(?Element $element): static
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
	 * @return static
	 */
	public function setType(?string $type): static
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
	 * @return static
	 */
	public function setLabelInsert(?Placeholder $insert): static
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
	 * @return static
	 */
	public function setFieldInsert(?Placeholder $insert): static
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
	 * @return static
	 */
	public function setInputInsert(?Placeholder $insert): static
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
	 * @return static
	 */
	public function setDescription(?string $description): static
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
	 * @return static
	 */
	public function setPlaceholder(?string $placeholder): static
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
	 * @return static
	 */
	public function setElementClass(?string $elementClass): static
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
	 * @return static
	 */
	public function setFieldClass(?string $fieldClass): static
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
	 * @return static
	 */
	public function setLabelCallback(?Closure $labelCallback): static
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
	 * @return static
	 */
	public function setLabelClass(?string $labelClass): static
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
	 * @return static
	 */
	public function setLabelClassCallback(?Closure $labelClassCallback): static
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
	 * @return static
	 */
	public function setAttribute(string $attribute, int|string $value): static
	{
		$this->_attributes[$attribute] = $value;
		
		return $this;
	}
	
	/**
	 * @param string $attribute
	 *
	 * @return null|int|string
	 */
	public function getAttribute(string $attribute): null|int|string
	{
		return $this->_attributes[$attribute] ?? null;
	}
	
	/**
	 * @param array $attributes
	 * 
	 * @return static
	 */
	public function setAttributes(array $attributes): static
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
	 * @return static
	 */
	public function setAttributesCallback(?Closure $attributesCallback): static
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
	
	/* Input Attributes */
	/**
	 * @param string $attribute
	 * @param int|string $value
	 *
	 * @return static
	 */
	public function setInputAttribute(string $attribute, int|string $value): static
	{
		$this->_inputAttributes[$attribute] = $value;
		
		return $this;
	}
	
	/**
	 * @param string $attribute
	 *
	 * @return null|int|string
	 */
	public function getInputAttribute(string $attribute): null|int|string
	{
		return $this->_inputAttributes[$attribute] ?? null;
	}
	
	/**
	 * @param array $attributes
	 * 
	 * @return static
	 */
	public function setInputAttributes(array $attributes): static
	{
		$this->_inputAttributes = $attributes;
		
		return $this;
	}
	
	/**
	 * @return array
	 */
	public function getInputAttributes(): array
	{
		return $this->_inputAttributes;
	}
	
	/* Input Attributes callback */
	/**
	 * @param ?Closure $inputAttributesCallback
	 * 
	 * @return static
	 */
	public function setInputAttributesCallback(?Closure $inputAttributesCallback): static
	{
		$this->_inputAttributesCallback = $inputAttributesCallback;
		
		return $this;
	}
	
	/**
	 * @return ?Closure
	 */
	public function getInputAttributesCallback(): ?Closure
	{
		return $this->_inputAttributesCallback;
	}
	
	/* Option Attributes */
	/**
	 * @param string $attribute
	 * @param int|string $value
	 *
	 * @return static
	 */
	public function setOptionAttribute(string $attribute, int|string $value): static
	{
		$this->_optionAttributes[$attribute] = $value;
		
		return $this;
	}
	
	/**
	 * @param string $attribute
	 *
	 * @return null|int|string
	 */
	public function getOptionAttribute(string $attribute): null|int|string
	{
		return $this->_optionAttributes[$attribute] ?? null;
	}
	
	/**
	 * @param array $attributes
	 * 
	 * @return static
	 */
	public function setOptionAttributes(array $attributes): static
	{
		$this->_optionAttributes = $attributes;
		
		return $this;
	}
	
	/**
	 * @return array
	 */
	public function getOptionAttributes(): array
	{
		return $this->_optionAttributes;
	}
	
	/* Option Attributes callback */
	/**
	 * @param ?Closure $optionAttributesCallback
	 * 
	 * @return static
	 */
	public function setOptionAttributesCallback(?Closure $optionAttributesCallback): static
	{
		$this->_optionAttributesCallback = $optionAttributesCallback;
		
		return $this;
	}
	
	/**
	 * @return ?Closure
	 */
	public function getOptionAttributesCallback(): ?Closure
	{
		return $this->_optionAttributesCallback;
	}
	
	/* Option */
	/**
	 * @param string $key
	 * @param mixed $value
	 * 
	 * @return static
	 */
	public function setOption(string $key, mixed $value): static
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
	 * @return static
	 */
	public function setOptionCallback(?Closure $optionCallback): static
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
	 * @return static
	 */
	public function optionCallback(Option $option): static
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
	 * @return static
	 */
	public function setOptionWrap(?string $optionWrap): static
	{
		return $this->setOptionLabelWrap($optionWrap);
	}
	
	/**
	 * @param ?string $optionLabelWrap
	 * 
	 * @return static
	 */
	public function setOptionLabelWrap(?string $optionLabelWrap): static
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
	 * @return static
	 */
	public function setOptionLabelCallback(?Closure $optionLabelCallback): static
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
	 * @return static
	 */
	public function setOptionClassCallback(?Closure $optionClassCallback): static
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
	 * @return static
	 */
	public function setFieldLabelCallback(?Closure $labelCallback): static
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
