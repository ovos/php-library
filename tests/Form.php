<?php
declare(strict_types=1);

namespace Tests;

use Ovos\Test;
use Ovos\Form as BaseForm;

/**
 * Form
 *
 * @package Tests
 * @author Marcin Gil <mg@ovos.at>
 */
class Form extends Test
{
	public function subformId(): bool
	{
		$form = new BaseForm('form');
		$subform = new BaseForm('subform');
		$subform->setForm($form);
		
		return $subform->getId() === 'form_subform';
	}
	
	/*
	public function secondTest(): bool
	{
		return true;
	}
	*/
}
