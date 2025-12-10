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
	protected ?Element $element = null;
	
	protected ?string $type = null;
	
	protected ?string $description = null;
	
	protected ?string $placeholder = null;
	
	protected ?string $elementClass = null;
	
	protected ?string $fieldClass = null;
	
	protected ?Closure $labelCallback = null;
	
	protected ?string $labelClass = null;
	
	protected ?Closure $labelClassCallback = null;
	
	protected ?Placeholder $labelInsert = null;
	
	protected ?Placeholder $fieldInsert = null;
	
	protected ?Placeholder $inputInsert = null;
	
	protected array $attributes = [];
	
	protected ?Closure $attributesCallback = null;
	
	protected array $inputAttributes = [];
	
	protected ?Closure $inputAttributesCallback = null;
	
	protected array $optionAttributes = [];
	
	protected ?Closure $optionAttributesCallback = null;
	
	protected array $options = [];
	
	protected ?string $optionLabelWrap = null;
	
	protected ?Closure $optionCallback = null;
	
	protected ?Closure $optionLabelCallback = null;
	
	protected ?Closure $optionClassCallback = null;
	
	public function formElement(
		Element $element,
	): static
	{
		return new static($element);
	}
	
	public function __construct(
		?Element $element = null,
	)
	{
		parent::__construct();
		
		$this->setElement($element);
	}
	
	/* Element */
	public function setElement(
		?Element $element,
	): static
	{
		$this->element = $element;
		
		return $this;
	}
	
	public function getElement(): ?Element
	{
		return $this->element;
	}
	
	/* Type */
	public function setType(
		?string $type,
	): static
	{
		$this->type = $type;
		
		return $this;
	}
	
	public function getType(): ?string
	{
		return $this->type;
	}
	
	/* Label insert */
	public function setLabelInsert(
		?Placeholder $insert,
	): static
	{
		$this->labelInsert = $insert;
		
		return $this;
	}
	
	public function getLabelInsert(): ?Placeholder
	{
		return $this->labelInsert;
	}
	
	/* Field insert */
	public function setFieldInsert(
		?Placeholder $insert,
	): static
	{
		$this->fieldInsert = $insert;
		
		return $this;
	}
	
	public function getFieldInsert(): ?Placeholder
	{
		return $this->fieldInsert;
	}
	
	/* Input insert */
	public function setInputInsert(
		?Placeholder $insert,
	): static
	{
		$this->inputInsert = $insert;
		
		return $this;
	}
	
	public function getInputInsert(): ?Placeholder
	{
		return $this->inputInsert;
	}
	
	/* Description */
	public function setDescription(
		?string $description,
	): static
	{
		$this->description = $description;
		
		return $this;
	}
	
	public function getDescription(): ?string
	{
		return $this->description;
	}
	
	/* Placeholder */
	public function setPlaceholder(
		?string $placeholder,
	): static
	{
		$this->placeholder = $placeholder;
		
		return $this;
	}
	
	public function getPlaceholder(): ?string
	{
		return $this->placeholder;
	}
	
	/* Element class */
	public function setElementClass(
		?string $elementClass,
	): static
	{
		$this->elementClass = $elementClass;
		
		return $this;
	}
	
	public function getElementClass(): ?string
	{
		return $this->elementClass;
	}
	
	/* Field class */
	public function setFieldClass(
		?string $fieldClass,
	): static
	{
		$this->fieldClass = $fieldClass;
		
		return $this;
	}
	
	public function getFieldClass(): ?string
	{
		return $this->fieldClass;
	}
	
	/* Callback for label */
	public function setLabelCallback(
		?Closure $labelCallback,
	): static
	{
		$this->labelCallback = $labelCallback;
		
		return $this;
	}
	
	public function getLabelCallback(): ?Closure
	{
		return $this->labelCallback;
	}
	
	public function callLabelCallback(
		Option $option,
	): string
	{
		if($this->labelCallback === null)
		{
			return $option->getLabel();
		}
		
		return ($this->labelCallback)($option);
	}
	
	/* Label class */
	public function setLabelClass(
		?string $labelClass,
	): static
	{
		$this->labelClass = $labelClass;
		
		return $this;
	}
	
	public function getLabelClass(): ?string
	{
		return $this->labelClass;
	}
	
	/* Callback for label class */
	public function setLabelClassCallback(
		?Closure $labelClassCallback,
	): static
	{
		$this->labelClassCallback = $labelClassCallback;
		
		return $this;
	}
	
	public function getLabelClassCallback(): ?Closure
	{
		return $this->labelClassCallback;
	}
	
	public function callLabelClassCallback(): ?string
	{
		if($this->labelClassCallback === null)
		{
			return null;
		}
		
		return ($this->labelClassCallback)();
	}
	
	/* Attributes */
	public function setAttribute(
		string $attribute,
		int|string $value,
	): static
	{
		$this->attributes[$attribute] = $value;
		
		return $this;
	}
	
	public function getAttribute(
		string $attribute,
	): null|int|string
	{
		return $this->attributes[$attribute] ?? null;
	}
	
	public function setAttributes(
		array $attributes,
	): static
	{
		$this->attributes = $attributes;
		
		return $this;
	}
	
	public function getAttributes(): array
	{
		return $this->attributes;
	}
	
	/* Callback for attributes */
	public function setAttributesCallback(
		?Closure $attributesCallback,
	): static
	{
		$this->attributesCallback = $attributesCallback;
		
		return $this;
	}
	
	public function getAttributesCallback(): ?Closure
	{
		return $this->attributesCallback;
	}
	
	/* Input attributes */
	public function setInputAttribute(
		string $attribute,
		int|string $value,
	): static
	{
		$this->inputAttributes[$attribute] = $value;
		
		return $this;
	}
	
	public function getInputAttribute(
		string $attribute,
	): null|int|string
	{
		return $this->inputAttributes[$attribute] ?? null;
	}
	
	public function setInputAttributes(
		array $attributes,
	): static
	{
		$this->inputAttributes = $attributes;
		
		return $this;
	}
	
	public function getInputAttributes(): array
	{
		return $this->inputAttributes;
	}
	
	/* Callback for input attributes */
	public function setInputAttributesCallback(
		?Closure $inputAttributesCallback,
	): static
	{
		$this->inputAttributesCallback = $inputAttributesCallback;
		
		return $this;
	}
	
	public function getInputAttributesCallback(): ?Closure
	{
		return $this->inputAttributesCallback;
	}
	
	/* Option Attributes */
	public function setOptionAttribute(
		string $attribute,
		int|string $value,
	): static
	{
		$this->optionAttributes[$attribute] = $value;
		
		return $this;
	}
	
	public function getOptionAttribute(
		string $attribute,
	): null|int|string
	{
		return $this->optionAttributes[$attribute] ?? null;
	}
	
	public function setOptionAttributes(
		array $attributes,
	): static
	{
		$this->optionAttributes = $attributes;
		
		return $this;
	}
	
	public function getOptionAttributes(): array
	{
		return $this->optionAttributes;
	}
	
	/* Option Attributes callback */
	public function setOptionAttributesCallback(
		?Closure $optionAttributesCallback,
	): static
	{
		$this->optionAttributesCallback = $optionAttributesCallback;
		
		return $this;
	}
	
	public function getOptionAttributesCallback(): ?Closure
	{
		return $this->optionAttributesCallback;
	}
	
	/* Option */
	public function setOption(
		string $key,
		mixed $value,
	): static
	{
		$this->options[$key] = $value;
		
		return $this;
	}
	
	public function getOption(
		string $key,
	): mixed
	{
		return $this->options[$key] ?? null;
	}
	
	/* Option callback */
	public function setOptionCallback(
		?Closure $optionCallback,
	): static
	{
		$this->optionCallback = $optionCallback;
		
		return $this;
	}
	
	public function getOptionCallback(): ?Closure
	{
		return $this->optionCallback;
	}
	
	public function callOptionCallback(
		Option $option,
	): static
	{
		if($this->optionCallback !== null)
		{
			($this->optionCallback)($this, $option);
		}
		
		return $this;
	}
	
	/* Option label wrap */
	public function setOptionLabelWrap(
		?string $optionLabelWrap,
	): static
	{
		$this->optionLabelWrap = $optionLabelWrap;
		
		return $this;
	}
	public function getOptionLabelWrap(): ?string
	{
		return $this->optionLabelWrap;
	}
	
	public function callOptionLabelWrap(
		string $label,
	): string
	{
		if($this->optionLabelWrap === null)
		{
			return $label;
		}
		
		return sprintf($this->optionLabelWrap, $label);
	}
	
	/* Option label callback */
	public function setOptionLabelCallback(
		?Closure $optionLabelCallback,
	): static
	{
		$this->optionLabelCallback = $optionLabelCallback;
		
		return $this;
	}
	
	public function getOptionLabelCallback(): ?Closure
	{
		return $this->optionLabelCallback;
	}
	
	public function callOptionLabelCallback(
		Option $option,
	): string
	{
		if($this->optionLabelCallback === null)
		{
			return $option->getLabel();
		}
		
		return ($this->optionLabelCallback)($option);
	}
	
	/* Option class callback */
	public function setOptionClassCallback(
		?Closure $optionClassCallback,
	): static
	{
		$this->optionClassCallback = $optionClassCallback;
		
		return $this;
	}
	
	public function getOptionClassCallback(): ?Closure
	{
		return $this->optionClassCallback;
	}
	
	public function callOptionClassCallback(
		Option $option,
	): ?string
	{
		if($this->optionClassCallback === null)
		{
			return null;
		}
		
		return ($this->optionClassCallback)($option);
	}
	
	public function render(
		Element $element,
	): string
	{
		$view = new View('helpers/form-element.phtml');
		$view->helper = $this;
		$view->element = $element;
		
		return $view->render();
	}
	
	public function __toString(): string
	{
		return $this->render($this->element);
	}
}
