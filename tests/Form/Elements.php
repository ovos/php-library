<?php
declare(strict_types=1);

namespace Tests\Form;

use Ovos\Form\Element;
use Ovos\Form\Filter;
use Ovos\Form\Json;
use Ovos\Form\Normalizer;
use Ovos\Form\Validator;
use Ovos\Test;

use function count;
use function is_array;
use function is_string;
use function strtolower;
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
			&& $form->getPresentValues()['origins'] === ['normalized'];
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
			&& $form->getPresentValues()['name'] === 'X-ONE';
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
			&& $form->getPresentValues()['slug'] === 'accepted';
	}
	
	/**
	 * Casts run only in getCastValue()/getPresentValues() — validation sees the
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
			&& $form->getPresentValues()['installation_id'] === null // storage: cast
			&& $form->getValues()['installation_id'] === '0';     // HTML path: uncast
	}
	
	/** Element\Text: scalars pass and store as strings, an array is one field error */
	public function textGatesAndCasts(): bool
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
	
	/** Element\Number: junk is a field error, "15" stores as 15, unset passes */
	public function numberGatesAndCasts(): bool
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
	
	/** Element\Flag: truthy is 1, falsy and null are 0 — a NOT NULL column never meets NULL */
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
	
	/**
	 * The form's own idiom, kept: an element is created on FIRST ACCESS and
	 * typed in the same chain that configures it — no class, no setElement().
	 * The Element\* classes are the same machinery spelled as classes.
	 */
	public function aFieldIsTypedOnFirstAccess(): bool
	{
		$form = new Json;
		$form->period->asNumber('must be a number');
		$form->setValues(['period' => '12abc']);
		$junk = $form->isValid() === false
			&& $form->getErrorMessages()['period'] === 'must be a number';
		
		$form = new Json;
		$form->period->asNumber();
		$form->active->asFlag();
		$form->types->asCollection();
		$form->title->asText();
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
	
	/**
	 * A value the gate refuses has no usable value: getValue() answers null
	 * WITHOUT running the filters or normalizers — they are written assuming
	 * the gate protected them, and a controller reading a failed field must
	 * not trip a warning that validation already reported as a clean field
	 * error.
	 */
	public function aGateRefusedValueNeverReachesTheNormalizers(): bool
	{
		$normalizerRan = false;
		$form = new Json;
		$form->period->asNumber();
		$form->period->addNormalizer(function(mixed $value) use (&$normalizerRan): mixed
		{
			$normalizerRan = true;
			
			return $value;
		});
		$form->setValues(['period' => ['an', 'array']]);
		
		return $form->isValid() === false
			&& $form->getErrorMessages()['period'] === '"period" must be a number.'
			&& $form->period->getValue() === null
			&& $normalizerRan === false;
	}
	
	/** every gate guards its element's reads the same way */
	public function everyGateRefusalReadsAsNull(): bool
	{
		$form = new Json;
		$form->title->asText();
		$form->types->asCollection();
		$form->setValues(['title' => ['a'], 'types' => 'scalar']);
		$form->isValid();
		
		return $form->title->getValue() === null
			&& $form->title->getInputValue() === null
			&& $form->types->getValue() === null;
	}
	
	/**
	 * The whole pipeline, in its documented order: filters clean, normalizers
	 * judge the whole value, validators see the uncast result, casts produce
	 * the storage form last - each stage exactly once per value.
	 */
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
			->addNormalizer(function(mixed $value) use (&$order): mixed
			{
				$order[] = 'normalizer';
				
				return $value;
			})
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
		
		return $order === ['filter', 'normalizer', 'validator', 'cast'];
	}
	
	/** casts chain - the typed element's storage cast first, then addCast() */
	public function castsChainInOrder(): bool
	{
		$form = new Json;
		$form->installation
			->asNumber()
			->addCast(static fn(mixed $value): mixed => $value > 0 ? $value : null);
		$form->setValues(['installation' => '0']);
		$form->isValid();
		
		return $form->getPresentValues()['installation'] === null
			&& $form->getValues()['installation'] === '0';
	}
	
	/**
	 * asInteger — the INT column's field: '15' stores as 15, 60.0 as 60, ''
	 * and null as NULL; 5.7 is refused with the step's message; junk is the
	 * gate's error; a Range on the field reports its bounds first — 0.5 is
	 * "between", not "whole"
	 */
	public function asIntegerIsTheIntColumnsField(): bool
	{
		$stored = static function(mixed $value): array
		{
			$form = new Json;
			$form->period->asInteger('between 1 and 10080', 'must be a whole number of minutes')
				->addValidator(new Validator\Range(1, 10080, message: 'between 1 and 10080'));
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
			// the gate's text is the first argument, as asNumber() has it
			&& $stored('12abc') === [false, 'between 1 and 10080'];
	}
	
	/**
	 * asChoice — a closed list: trimmed and lowercased onto a choice, refused
	 * off the list with the message (both for a wrong word and a wrong
	 * shape), '' an answer only when listed, and a default message naming the
	 * choices
	 */
	public function asChoiceIsAClosedList(): bool
	{
		$stored = static function(mixed $value, array $choices, ?string $message = null): array
		{
			$form = new Json;
			$form->format->asChoice($choices, $message);
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
	
	/** one message for the field when the step needs no text of its own; the defaults without any */
	public function asIntegerMessagesFallBack(): bool
	{
		$one = new Json;
		$one->tier->asInteger('pick a tier from 1 to 4');
		$one->setValues(['tier' => 2.5]);
		$bare = new Json;
		$bare->tier->asInteger();
		$bare->setValues(['tier' => 2.5]);
		$junk = new Json;
		$junk->tier->asInteger();
		$junk->setValues(['tier' => 'x']);
		
		return $one->isValid() === false && $one->getErrorMessages()['tier'] === 'pick a tier from 1 to 4'
			&& $bare->isValid() === false && $bare->getErrorMessages()['tier'] === '"tier" must be a whole number.'
			&& $junk->isValid() === false && $junk->getErrorMessages()['tier'] === '"tier" must be a number.';
	}
	
	/** the number gate passes floats and stores them as floats */
	public function numberStoresFloats(): bool
	{
		$form = new Json;
		$form->ratio->asNumber();
		$form->setValues(['ratio' => '1.5']);
		
		return $form->isValid()
			&& $form->getPresentValues()['ratio'] === 1.5;
	}
	
	/** a reusable Normalizer subclass plugs in like the callable form */
	public function aNormalizerSubclassPlugsIn(): bool
	{
		$normalizer = new class extends Normalizer
		{
			public function normalize(
				mixed $value,
			): mixed
			{
				return is_string($value) ? strtolower($value) : null;
			}
		};
		
		$form = new Json;
		$form->slug->addNormalizer($normalizer);
		$form->setValues(['slug' => 'MiXeD']);
		
		return $form->isValid()
			&& $form->getPresentValues()['slug'] === 'mixed'
			&& $form->slug->getNormalizers() === [$normalizer];
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
