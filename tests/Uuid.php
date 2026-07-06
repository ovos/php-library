<?php
declare(strict_types=1);

namespace Tests;

use Ovos\Test;
use Ovos\Uuid as Subject;

use function count;
use function hexdec;
use function in_array;
use function strtolower;
use function substr;

/**
 * Uuid
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Uuid extends Test
{
	public function v4IsCanonicalAndValid(): bool
	{
		$uuid = Subject::v4();
		
		return Subject::isValid($uuid) === true
			&& Subject::isValid('not-a-uuid') === false
			&& Subject::isValid('0aea92ed8c57414485d01582257f4d67') === false;
	}
	
	/**
	 * The version nibble must be 4 and the variant nibble 8-b (10xx)
	 */
	public function v4PinsVersionAndVariant(): bool
	{
		for($attempt = 0; $attempt < 64; $attempt++)
		{
			$uuid = Subject::v4();
			
			if(substr($uuid, 14, 1) !== '4')
			{
				return false;
			}
			
			$variant = hexdec(substr($uuid, 19, 1));
			if(in_array($variant, [8, 9, 10, 11], true) === false)
			{
				return false;
			}
		}
		
		return true;
	}
	
	public function v4DoesNotCollide(): bool
	{
		$seen = [];
		for($attempt = 0; $attempt < 10000; $attempt++)
		{
			$seen[Subject::v4()] = true;
		}
		
		return count($seen) === 10000;
	}
	
	/**
	 * Uppercase input is valid per the RFC; our output stays lowercase
	 */
	public function validationIsCaseInsensitive(): bool
	{
		$uuid = Subject::v4();
		
		return Subject::isValid('0AEA92ED-8C57-4144-85D0-1582257F4D67') === true
			&& $uuid === strtolower($uuid);
	}
}
