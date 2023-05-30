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
		$parentForm = new BaseForm('parent');
		$form = new BaseForm('sub');
		$form->setForm($parentForm);
		
		return $form->getId() === 'parent_sub';
	}
}
