<?php
declare(strict_types=1);

namespace Tests\Password;

use Ovos\Password;
use Ovos\Test;

use function str_replace;
use function strlen;
use function strpbrk;
use function strspn;

/**
 * Generate: Password::generate() draws only from the chosen sets, puts one of each chosen set in, and keeps its
 * length - with and without dashes - for every combination of the sets
 */
class Generate extends Test
{
	protected const array SETS = [
		Password::SET_LOWERCASE => 'abcdefghjkmnpqrstuvwxyz',
		Password::SET_UPPERCASE => 'ABCDEFGHJKMNPQRSTUVWXYZ',
		Password::SET_DIGITS => '23456789',
		Password::SET_SPECIAL => '!@#$%&*_?',
		Password::SET_SPECIAL_FTP => '!@#%*()_?',
	];
	
	// passwords per combination: enough for a character outside the sets to show
	protected const int ROUNDS = 200;
	
	public function onlyCharactersOfTheChosenSets(): bool
	{
		foreach($this->combinations() as $sets)
		{
			$allowed = $this->characters($sets);
			for($i = 0; $i < self::ROUNDS; $i++)
			{
				$password = Password::generate(12, dashes: false, availableSets: $sets);
				if(strspn($password, $allowed) !== strlen($password))
				{
					return false;
				}
			}
		}
		
		return true;
	}
	
	public function everyChosenSetIsPresent(): bool
	{
		foreach($this->combinations() as $sets)
		{
			for($i = 0; $i < self::ROUNDS; $i++)
			{
				$password = Password::generate(12, dashes: false, availableSets: $sets);
				foreach(self::SETS as $set => $characters)
				{
					if(($sets & $set) !== 0
						&& strpbrk($password, $characters) === false)
					{
						return false;
					}
				}
			}
		}
		
		return true;
	}
	
	/**
	 * The length asked for, also when it equals the number of sets (no characters beyond the one per set); the
	 * dashes come on top and are the only addition
	 */
	public function lengthWithAndWithoutDashes(): bool
	{
		foreach([4, 9, 12, 16] as $length)
		{
			$plain = Password::generate($length, dashes: false);
			$dashed = Password::generate($length);
			
			if(strlen($plain) !== $length
				|| strlen(str_replace('-', '', $dashed)) !== $length
				|| strspn($dashed, $this->characters(self::defaultSets()) . '-') !== strlen($dashed))
			{
				return false;
			}
		}
		
		return true;
	}
	
	/**
	 * Every non-empty combination of the five sets (1 to 31)
	 *
	 * @return int[]
	 */
	protected function combinations(): array
	{
		$all = 0;
		foreach(self::SETS as $set => $characters)
		{
			$all|= $set;
		}
		
		$combinations = [];
		for($sets = 1; $sets <= $all; $sets++)
		{
			$combinations[] = $sets;
		}
		
		return $combinations;
	}
	
	protected function characters(
		int $sets,
	): string
	{
		$characters = '';
		foreach(self::SETS as $set => $setCharacters)
		{
			if(($sets & $set) !== 0)
			{
				$characters.= $setCharacters;
			}
		}
		
		return $characters;
	}
	
	/**
	 * generate()'s default sets
	 */
	protected static function defaultSets(): int
	{
		return Password::SET_LOWERCASE
			| Password::SET_UPPERCASE
			| Password::SET_DIGITS
			| Password::SET_SPECIAL;
	}
}
