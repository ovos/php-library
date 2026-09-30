<?php
declare(strict_types=1);

namespace Tests\Password;

use Ovos\Password;
use Ovos\Password\Hash as BaseHash;
use Ovos\Test;

use function defined;
use function is_string;
use function password_hash;

/**
 * Hash: PHP's argon2id defaults, and hashes made with other parameters keep verifying
 */
class Hash extends Test
{
	public function argon2idUsesPhpDefaults(): bool
	{
		if(defined('PASSWORD_ARGON2ID') === false)
		{
			return (new BaseHash())->getAlgorithm() === PASSWORD_BCRYPT;
		}
		
		$hash = new BaseHash();
		
		return $hash->getAlgorithm() === PASSWORD_ARGON2ID
			&& $hash->getOptions() === [
				'memory_cost' => PASSWORD_ARGON2_DEFAULT_MEMORY_COST,
				'time_cost' => PASSWORD_ARGON2_DEFAULT_TIME_COST,
				'threads' => PASSWORD_ARGON2_DEFAULT_THREADS,
			];
	}
	
	public function hashVerifiesOnlyItsPassword(): bool
	{
		$hash = Password::hash('correct horse battery staple');
		
		return is_string($hash)
			&& Password::verify('correct horse battery staple', $hash)
			&& Password::verify('correct horse battery stapler', $hash) === false
			&& (new BaseHash())->needsRehash($hash) === false;
	}
	
	/**
	 * A hash made with other argon2id parameters - like the former defaults (128 MiB, 40 passes; cheaper ones here to
	 * keep the test fast, the parameters travel in the hash either way) - still verifies and is flagged for a rehash
	 */
	public function hashesWithOtherParametersStillVerify(): bool
	{
		if(defined('PASSWORD_ARGON2ID') === false)
		{
			return true;
		}
		
		$other = password_hash('correct horse battery staple', PASSWORD_ARGON2ID, [
			'memory_cost' => 16384,
			'time_cost' => 2,
			'threads' => 1,
		]);
		
		return Password::verify('correct horse battery staple', $other)
			&& Password::verify('wrong', $other) === false
			&& (new BaseHash())->needsRehash($other) === true;
	}
}
