<?php
declare(strict_types=1);

namespace Tests\Form;

use Ovos\Test;
use Ovos\Form;
use Ovos\Form\Element as BaseElement;

/**
 * Element
 *
 * @package Tests
 * @author Marcin Gil <mg@ovos.at>
 */
class Element extends Test
{
	public function value(): bool
	{
		$form = new Form('form');
		$form->setId('test');
		$form->element->setValue('value');
		
		return $form->getValues()['element'] === 'value';
	}
	
	public function subformValue(): bool
	{
		$parentForm = new Form('parent');
		$form = new Form('sub');
		$form->setForm($parentForm);
		$form->element->setValue('value');
		
		return $form->getValues()['element'] === 'value';
	}
}
