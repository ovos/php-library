<?php
declare(strict_types=1);

namespace Tests\Form;

use Ovos\Test;
use Ovos\Form;
use Ovos\Form\Element as BaseElement;
use Ovos\Form\Filter as Filters;

/**
 * Filter
 *
 * @package Tests
 * @author Marcin Gil <mg@ovos.at>
 */
class Filter extends Test
{
	public function checked(): bool
	{
		$form = new Form;
		$form->checkbox
			->addFilter(new Filters\Checked);
		
		$form->setValues(['checkbox' => 'on']);
		$values = $form->getUserValues();
		
		return $values['checkbox'] === 1;
	}
	
	public function checkedDefault(): bool
	{
		$form = new Form;
		$form->checkbox
			->setDefault(0)
			->addFilter(new Filters\Checked);
		
		$form->setValues(['checkbox' => 'on']);
		$values = $form->getInputValues();
		
		return $values['checkbox'] === 1;
	}
	
	public function unchecked(): bool
	{
		$form = new Form;
		$form->checkbox
			->addFilter(new Filters\Checked);
		
		$form->setValues([]);
		$values = $form->getUserValues();
		
		return $values['checkbox'] === 0;
	}
	
	public function uncheckedDefault(): bool
	{
		$form = new Form;
		$form->checkbox
			->setDefault(1)
			->addFilter(new Filters\Checked);
		
		$form->setValues([]);
		$values = $form->getInputValues();
		
		return $values['checkbox'] === 1;
	}
}
