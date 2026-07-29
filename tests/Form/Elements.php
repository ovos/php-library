<?php
declare(strict_types=1);

namespace Tests\Form;

use Ovos\Form\Element;
use Ovos\Form\Filter;
use Ovos\Form\Json;
use Ovos\Form\Validator;
use Ovos\Test;

use function count;
use function is_array;
use function strtoupper;

/**
 * The element machinery added for JSON forms — normalizers, casts and the
 * typed elements — plus the guarantee the whole batch was built under: the
 * HTML path (filters, validators, getValues) behaves exactly as before.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Elements extends Test
{
	/**
	 * THE case filters cannot express: a whole-array transform. Filters
	 * apply per item of an array value (the HTML multi-input contract), so
	 * a normalizer attached as a filter would receive strings one at a
	 * time — here it must receive the array itself.
	 */
	public function aNormalizerSeesAnArrayValueWhole(): bool
	{
		$seen = null;
		$form = new Json;
		$form->origins->addNormalizer(function(mixed $value) use (&$seen): mixed
		{
			$seen = $value;
			
			return is_array($value) ? ['normalized'] : null;
		});
		$form->setValues(['origins' => ['https://a.test', 'https://b.test']]);
		
		return $form->isValid()
			&& $seen === ['https://a.test', 'https://b.test']
			&& $form->getSentValues()['origins'] === ['normalized'];
	}
	
	/** a null return rejects, with the message, and validators are skipped */
	public function aNormalizerRejectsWithItsMessage(): bool
	{
		$validatorRan = false;
		$form = new Json;
		$form->trace_url
			->addNormalizer(static fn(mixed $value): mixed => null,
				'must be an https:// URL')
			->addValidator(new Validator\Callback(
				function(mixed $value) use (&$validatorRan): bool
				{
					$validatorRan = true;
					
					return true;
				}));
		$form->setValues(['trace_url' => 'ftp://nope']);
		
		$valid = $form->isValid();
		$errors = $form->getErrorMessages();
		
		return $valid === false
			&& $errors['trace_url'] === 'must be an https:// URL'
			&& $validatorRan === false;
	}
	
	/** normalizers chain; the second sees the first's output */
	public function normalizersChain(): bool
	{
		$form = new Json;
		$form->name
			->addNormalizer(static fn(mixed $value): mixed => $value . '-one')
			->addNormalizer(static fn(mixed $value): mixed => strtoupper((string)$value));
		$form->setValues(['name' => 'x']);
		
		return $form->isValid()
			&& $form->getSentValues()['name'] === 'X-ONE';
	}
	
	/** filters still run first (trim before the normalizer judges) */
	public function filtersRunBeforeNormalizers(): bool
	{
		$form = new Json;
		$form->slug
			->addFilter(new Filter\Trim)
			->addNormalizer(static fn(mixed $value): mixed
				=> $value === 'clean' ? 'accepted' : null);
		$form->setValues(['slug' => '  clean  ']);
		
		return $form->isValid()
			&& $form->getSentValues()['slug'] === 'accepted';
	}
	
	/**
	 * Casts run only in getCastValue()/getSentValues() — validation sees the
	 * UNCAST value, and the HTML accessors never apply them at all.
	 */
	public function castsAreForTheStorageFormOnly(): bool
	{
		$sawInValidator = null;
		$form = new Json;
		$form->installation_id
			->addValidator(new Validator\Callback(
				function(mixed $value) use (&$sawInValidator): bool
				{
					$sawInValidator = $value;
					
					return true;
				}))
			->addCast(static fn(mixed $value): mixed
				=> (int)$value > 0 ? (int)$value : null);
		$form->setValues(['installation_id' => '0']);
		$form->isValid();
		
		return $sawInValidator === '0'                       // validators: uncast
			&& $form->getSentValues()['installation_id'] === null // storage: cast
			&& $form->getValues()['installation_id'] === '0';     // HTML path: uncast
	}
	
	/** Element\Text: scalars pass and store as strings, an array is one field error */
	public function textGatesAndCasts(): bool
	{
		$form = new Json;
		$form->setElement('title', new Element\Text);
		$form->setValues(['title' => 5]);
		$intPasses = $form->isValid() && $form->getSentValues()['title'] === '5';
		
		$form = new Json;
		$form->setElement('title', new Element\Text);
		$form->title->addValidator(new Validator\NotEmpty); // must not also fire
		$form->setValues(['title' => ['an', 'array']]);
		$arrayFails = $form->isValid() === false
			&& count($form->getErrors()) === 1
			&& $form->getErrorMessages()['title'] === '"title" must be text.';
		
		return $intPasses && $arrayFails;
	}
	
	/** Element\Number: junk is a field error, "15" stores as 15, unset passes */
	public function numberGatesAndCasts(): bool
	{
		$form = new Json;
		$form->setElement('period', new Element\Number);
		$form->setValues(['period' => '15']);
		$numeric = $form->isValid() && $form->getSentValues()['period'] === 15;
		
		$form = new Json;
		$form->setElement('period', new Element\Number);
		$form->setValues(['period' => '12abc']);
		$junk = $form->isValid() === false
			&& $form->getErrorMessages()['period'] === '"period" must be a number.';
		
		$form = new Json;
		$form->setElement('period', new Element\Number);
		$form->setValues(['period' => null]);
		$unset = $form->isValid() && $form->getSentValues()['period'] === null;
		
		return $numeric && $junk && $unset;
	}
	
	/** Element\Flag: truthy is 1, falsy and null are 0 — a NOT NULL column never meets NULL */
	public function flagCastsToOneOrZero(): bool
	{
		$form = new Json;
		$form->setElement('active', new Element\Flag);
		$form->setElement('enabled', new Element\Flag);
		$form->setElement('off', new Element\Flag);
		$form->setValues(['active' => true, 'enabled' => null, 'off' => false]);
		
		$values = $form->getSentValues();
		
		return $form->isValid()
			&& $values === ['active' => 1, 'enabled' => 0, 'off' => 0];
	}
	
	/** Element\Collection: null and arrays pass, a scalar is one field error */
	public function collectionGates(): bool
	{
		$form = new Json;
		$form->setElement('types', new Element\Collection);
		$form->setValues(['types' => 'not-a-list']);
		$scalarFails = $form->isValid() === false
			&& $form->getErrorMessages()['types'] === '"types" must be a list.';
		
		$form = new Json;
		$form->setElement('types', new Element\Collection);
		$form->setValues(['types' => null]);
		$nullPasses = $form->isValid();
		
		return $scalarFails && $nullPasses;
	}
	
	/** withMessage covers every code; the ctor message param is the same thing */
	public function oneMessageCoversEveryErrorCode(): bool
	{
		$form = new Json;
		$form->grace->addValidator(
			new Validator\Range(0, 10, nullable: false, message: 'between 0 and 10'));
		
		$form->setValues(['grace' => 99]);
		$tooLarge = $form->isValid() === false
			&& $form->getErrorMessages()['grace'] === 'between 0 and 10';
		
		$form->setValues(['grace' => 'abc']);
		$form->grace->reset();
		$junk = $form->isValid() === false
			&& $form->getErrorMessages()['grace'] === 'between 0 and 10';
		
		return $tooLarge && $junk;
	}
	
	/**
	 * The guarantee this batch was built under: an element used the HTML way
	 * — filters + validators, read through getValues() — behaves exactly as
	 * before. No normalizers, no casts, no gates in the way.
	 */
	public function theHtmlPathIsUntouched(): bool
	{
		$form = new \Ovos\Form;
		$form->username
			->setLabel('E-mail')
			->addFilter(new Filter\Trim)
			->addValidator(new Validator\NotEmpty)
			->addValidator(new Validator\EmailAddress);
		$form->username->setValue('  test@test.com  ');
		
		return $form->isValid()
			&& $form->getValues()['username'] === 'test@test.com';
	}
}
