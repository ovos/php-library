<?php
declare(strict_types=1);

namespace Tests\Form;

use Ovos\Form\Element;
use Ovos\Form\Filter;
use Ovos\Form\Json;
use Ovos\Form\Validator;
use Ovos\Test;

use function array_filter;
use function count;
use function implode;
use function is_array;
use function is_string;
use function strtolower;
use function strtoupper;
use function trim;

class Elements extends Test
{
	public function aWholeValueFilterReceivesTheArray(): bool
	{
		$seen = null;
		$form = new Json;
		$form->origins->addFilter(new Filter\Callback(function(mixed $value) use (&$seen): mixed
		{
			$seen = $value;
			
			return is_array($value) ? ['normalized'] : null;
		}), each: false);
		$form->setValues(['origins' => ['https://a.test', 'https://b.test']]);
		
		return $form->isValid()
			&& $seen === ['https://a.test', 'https://b.test']
			&& $form->getPresentValues()['origins'] === ['normalized'];
	}
	
	public function aNullResultIsRejectedByItsValidator(): bool
	{
		$validatorRan = false;
		$form = new Json;
		$form->trace_url
			->addFilter(new Filter\Callback(
				static fn(mixed $value): mixed => null), each: false)
			->addValidator(new Validator\NotNull('must be an https:// URL'), breakOnFailure: true)
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
	
	public function wholeValueFiltersChain(): bool
	{
		$form = new Json;
		$form->name
			->addFilter(new Filter\Callback(
				static fn(mixed $value): mixed => $value . '-one'), each: false)
			->addFilter(new Filter\Callback(
				static fn(mixed $value): mixed => strtoupper((string)$value)), each: false);
		$form->setValues(['name' => 'x']);
		
		return $form->isValid()
			&& $form->getPresentValues()['name'] === 'X-ONE';
	}
	
	public function itemFiltersRunBeforeTheFollowingWholeValueFilter(): bool
	{
		$form = new Json;
		$form->slug
			->addFilter(new Filter\Trim)
			->addFilter(new Filter\Callback(
				static fn(mixed $value): mixed
				=> $value === 'clean' ? 'accepted' : null), each: false);
		$form->setValues(['slug' => '  clean  ']);
		
		return $form->isValid()
			&& $form->getPresentValues()['slug'] === 'accepted';
	}
	
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
			&& $form->getPresentValues()['installation_id'] === null // storage: cast
			&& $form->getValues()['installation_id'] === '0';     // HTML path: uncast
	}
	
	public function textInputValidationAndCasting(): bool
	{
		$form = new Json;
		$form->setElement('title', new Element\Text);
		$form->setValues(['title' => 5]);
		$intPasses = $form->isValid() && $form->getPresentValues()['title'] === '5';
		
		$form = new Json;
		$form->setElement('title', new Element\Text);
		$form->title->addValidator(new Validator\NotEmpty); // must not also fire
		$form->setValues(['title' => ['an', 'array']]);
		$arrayFails = $form->isValid() === false
			&& count($form->getErrors()) === 1
			&& $form->getErrorMessages()['title'] === '"title" must be text.';
		
		return $intPasses && $arrayFails;
	}
	
	public function numberInputValidationAndCasting(): bool
	{
		$form = new Json;
		$form->setElement('period', new Element\Number);
		$form->setValues(['period' => '15']);
		$numeric = $form->isValid() && $form->getPresentValues()['period'] === 15;
		
		$form = new Json;
		$form->setElement('period', new Element\Number);
		$form->setValues(['period' => '12abc']);
		$junk = $form->isValid() === false
			&& $form->getErrorMessages()['period'] === '"period" must be a number.';
		
		$form = new Json;
		$form->setElement('period', new Element\Number);
		$form->setValues(['period' => null]);
		$unset = $form->isValid() && $form->getPresentValues()['period'] === null;
		
		return $numeric && $junk && $unset;
	}
	
	public function flagCastsToOneOrZero(): bool
	{
		$form = new Json;
		$form->setElement('active', new Element\Flag);
		$form->setElement('enabled', new Element\Flag);
		$form->setElement('off', new Element\Flag);
		$form->setValues(['active' => true, 'enabled' => null, 'off' => false]);
		
		$values = $form->getPresentValues();
		
		return $form->isValid()
			&& $values === ['active' => 1, 'enabled' => 0, 'off' => 0];
	}
	
	public function collectionInputValidation(): bool
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
	
	public function rulesCanBeConfiguredOnFirstAccess(): bool
	{
		$form = new Json;
		$form->period->addInputValidator((new Validator\Number)->withMessage('must be a number'))
			->addCast(new Filter\Number);
		$form->setValues(['period' => '12abc']);
		$junk = $form->isValid() === false
			&& $form->getErrorMessages()['period'] === 'must be a number';
		
		$form = new Json;
		$form->period->addInputValidator(new Validator\Number)
			->addCast(new Filter\Number);
		$form->active->addCast(new Filter\Flag);
		$form->types->addInputValidator(new Validator\Collection);
		$form->title->addInputValidator(new Validator\Text)
			->addCast(new Filter\Text);
		$form->setValues([
			'period' => '15',
			'active' => true,
			'types' => null,
			'title' => 7,
		]);
		$values = $form->getPresentValues();
		$typed = $form->isValid()
			&& $values['period'] === 15
			&& $values['active'] === 1
			&& $values['types'] === null
			&& $values['title'] === '7';
		
		return $junk && $typed;
	}
	
	public function invalidRawInputNeverReachesFilters(): bool
	{
		$filterRan = false;
		$form = new Json;
		$form->period->addInputValidator(new Validator\Number)
			->addCast(new Filter\Number);
		$form->period->addFilter(new Filter\Callback(function(mixed $value) use (&$filterRan): mixed
		{
			$filterRan = true;
			
			return $value;
		}), each: false);
		$form->setValues(['period' => ['an', 'array']]);
		
		return $form->isValid() === false
			&& $form->getErrorMessages()['period'] === '"period" must be a number.'
			&& $form->period->getValue() === null
			&& $filterRan === false;
	}
	
	public function invalidRawInputReadsAsNull(): bool
	{
		$form = new Json;
		$form->title->addInputValidator(new Validator\Text)
			->addCast(new Filter\Text);
		$form->types->addInputValidator(new Validator\Collection);
		$form->setValues(['title' => ['a'], 'types' => 'scalar']);
		$form->isValid();
		
		return $form->title->getValue() === null
			&& $form->title->getInputValue() === null
			&& $form->types->getValue() === null;
	}
	
	public function thePipelineRunsInOrder(): bool
	{
		$order = [];
		$form = new Json;
		$form->field
			->addFilter(new Filter\Callback(function(mixed $value) use (&$order): mixed
			{
				$order[] = 'filter';
				
				return $value;
			}))
			->addFilter(new Filter\Callback(function(mixed $value) use (&$order): mixed
			{
				$order[] = 'whole-filter';
				
				return $value;
			}), each: false)
			->addValidator(new Validator\Callback(function(mixed $value) use (&$order): bool
			{
				$order[] = 'validator';
				
				return true;
			}))
			->addCast(function(mixed $value) use (&$order): mixed
			{
				$order[] = 'cast';
				
				return $value;
			});
		$form->setValues(['field' => 'x']);
		$form->isValid();
		$form->getPresentValues();
		
		return $order === ['filter', 'whole-filter', 'validator', 'cast'];
	}
	
	public function castsChainInOrder(): bool
	{
		$form = new Json;
		$form->installation
			->addInputValidator(new Validator\Number)
			->addCast(new Filter\Number)
			->addCast(static fn(mixed $value): mixed => $value > 0 ? $value : null);
		$form->setValues(['installation' => '0']);
		$form->isValid();
		
		return $form->getPresentValues()['installation'] === null
			&& $form->getValues()['installation'] === '0';
	}
	
	public function integerRulesValidateBeforeCasting(): bool
	{
		$stored = static function(mixed $value): array
		{
			$form = new Json;
			$form->period->addInputValidator((new Validator\Number)->withMessage('between 1 and 10080'))
				->addCast(new Filter\Integer(nullable: true))
				->addValidator(new Validator\Range(1, 10080, message: 'between 1 and 10080'))
				->addValidator(new Validator\WholeNumber('must be a whole number of minutes'));
			$form->setValues(['period' => $value]);
			$valid = $form->isValid();
			
			return [$valid, $valid ? $form->getPresentValues()['period'] : ($form->getErrorMessages()['period'] ?? null)];
		};
		
		return $stored('15') === [true, 15]
			&& $stored(60.0) === [true, 60]
			&& $stored('') === [true, null]
			&& $stored(null) === [true, null]
			&& $stored(5.7) === [false, 'must be a whole number of minutes']
			&& $stored(0.5) === [false, 'between 1 and 10080']
			&& $stored('12abc') === [false, 'between 1 and 10080'];
	}
	
	public function aChoiceUsesFiltersAndAnExplicitMembershipRule(): bool
	{
		$stored = static function(mixed $value, array $choices, ?string $message = null): array
		{
			$message ??= 'must be one of ' . implode(', ', array_filter($choices, static fn(string $choice): bool => $choice !== ''));
			$form = new Json;
			$form->format->addInputValidator((new Validator\Text)->withMessage($message))
				->addFilter(new Filter\Callback(static fn(mixed $value): string => strtolower(trim((string)$value))))
				->addValidator(new Validator\InArray($choices, message: $message, nullable: false));
			$form->setValues(['format' => $value]);
			$valid = $form->isValid();
			
			return [$valid, $valid ? $form->getPresentValues()['format'] : ($form->getErrorMessages()['format'] ?? null)];
		};
		$formats = ['slack', 'telegram'];
		
		return $stored(' Telegram ', $formats) === [true, 'telegram']
			&& $stored('teams', $formats, 'unknown format') === [false, 'unknown format']
			&& $stored(['slack'], $formats, 'unknown format') === [false, 'unknown format']
			&& $stored('teams', $formats) === [false, 'must be one of slack, telegram']
			&& $stored('', $formats) === [false, 'must be one of slack, telegram']
			&& $stored('', ['', 'github']) === [true, '']
			&& $stored(null, ['', 'github']) === [true, '']
			&& $stored('x', ['', 'github']) === [false, 'must be one of github'];
	}
	
	public function integerMessagesBelongToTheirValidators(): bool
	{
		$one = new Json;
		$one->tier->addInputValidator((new Validator\Number)->withMessage('pick a tier from 1 to 4'))
			->addCast(new Filter\Integer(nullable: true))
			->addValidator(new Validator\WholeNumber('pick a tier from 1 to 4'));
		$one->setValues(['tier' => 2.5]);
		$bare = new Json;
		$bare->tier->addInputValidator(new Validator\Number)
			->addCast(new Filter\Integer(nullable: true))
			->addValidator(new Validator\WholeNumber());
		$bare->setValues(['tier' => 2.5]);
		$junk = new Json;
		$junk->tier->addInputValidator(new Validator\Number)
			->addCast(new Filter\Integer(nullable: true))
			->addValidator(new Validator\WholeNumber());
		$junk->setValues(['tier' => 'x']);
		
		return $one->isValid() === false && $one->getErrorMessages()['tier'] === 'pick a tier from 1 to 4'
			&& $bare->isValid() === false && $bare->getErrorMessages()['tier'] === '"tier" must be a whole number.'
			&& $junk->isValid() === false && $junk->getErrorMessages()['tier'] === '"tier" must be a number.';
	}
	
	public function numberStoresFloats(): bool
	{
		$form = new Json;
		$form->ratio->addInputValidator(new Validator\Number)
			->addCast(new Filter\Number);
		$form->setValues(['ratio' => '1.5']);
		
		return $form->isValid()
			&& $form->getPresentValues()['ratio'] === 1.5;
	}
	
	public function aWholeValueFilterSubclassPlugsIn(): bool
	{
		$filter = new class extends Filter
		{
			public function filter(
				mixed $value,
			): mixed
			{
				return is_string($value) ? strtolower($value) : null;
			}
		};
		
		$form = new Json;
		$form->slug->addFilter($filter, each: false);
		$form->setValues(['slug' => 'MiXeD']);
		
		return $form->isValid()
			&& $form->getPresentValues()['slug'] === 'mixed';
	}
	
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
