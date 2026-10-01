<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Password\Hash;
use Random\Randomizer;

use function array_unique;
use function count;
use function floor;
use function implode;
use function password_verify;
use function str_split;
use function strlen;
use function substr;
use function sqrt;

/**
 * Password
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Password
{
	// Generator sets
	public const int SET_LOWERCASE = 1;
	public const int SET_UPPERCASE = 2;
	public const int SET_DIGITS = 4;
	public const int SET_SPECIAL = 8;
	public const int SET_SPECIAL_FTP = 16;
	
	public static ?Hash $hashInstance = null;
	
	public static function hash(
		string $password,
		?int $algorithm = null,
		?array $options = null,
	): bool|string
	{
		return self::getHashInstance()
			->hash($password, $algorithm, $options);
	}
	
	public static function needsRehash(
		string $password,
		?int $algorithm = null,
		?array $options = null,
	): bool|string
	{
		return self::getHashInstance()
			->needsRehash($password, $algorithm, $options);
	}
	
	public static function getHashInstance(): Hash
	{
		if(self::$hashInstance === null)
		{
			self::$hashInstance = new Hash;
		}
		
		return self::$hashInstance;
	}
	
	public static function verify(
		string $password,
		string $hash,
	): bool
	{
		return password_verify($password, $hash);
	}
	
	/**
	 * Generates a strong password of N length containing at least one lower case letter,
	 * one uppercase letter, one digit, and one special character. The remaining characters
	 * in the password are chosen at random from those four sets.
	 *
	 * The available characters in each set are user-friendly - there are no ambiguous
	 * characters such as i, l, 1, o, 0, etc. This, coupled with the $add_dashes option,
	 * makes it much easier for users to manually type or speak their passwords.
	 * Note: the $add_dashes option will increase the length of the password by
	 * floor(sqrt(N)) characters.
	 *
	 * The characters are drawn by Random\Randomizer with its default engine,
	 * Random\Engine\Secure - cryptographically secure, like random_int().
	 *
	 * Based on
	 * @see https://gist.github.com/tylerhall/521810
	 */
	public static function generate(
		int $length = 9,
		bool $dashes = true,
		int $availableSets = self::SET_LOWERCASE
			+ self::SET_UPPERCASE
			+ self::SET_DIGITS
			+ self::SET_SPECIAL,
	): string
	{
		$sets = [];
		if($availableSets & self::SET_LOWERCASE)
		{
			$sets[] = 'abcdefghjkmnpqrstuvwxyz';
		}
		if($availableSets & self::SET_UPPERCASE)
		{
			$sets[] = 'ABCDEFGHJKMNPQRSTUVWXYZ';
		}
		if($availableSets & self::SET_DIGITS)
		{
			$sets[] = '23456789';
		}
		if($availableSets & self::SET_SPECIAL)
		{
			$sets[] = '!@#$%&*_?';
		}
		if($availableSets & self::SET_SPECIAL_FTP)
		{
			$sets[] = '!@#%*()_?'; // $ AND & are not accepted by ftp_pwd
		}
		
		// Random\Engine\Secure (the default engine): cryptographically secure,
		// unlike array_rand() and str_shuffle(), which drew from Mt19937 - whose
		// output can be predicted from enough of it
		$randomizer = new Randomizer;
		
		$all = '';
		$password = '';
		foreach($sets as $set)
		{
			// one of each set, so every chosen set is represented
			$password.= $randomizer->getBytesFromString($set, 1);
			$all.= $set;
		}
		
		// SET_SPECIAL and SET_SPECIAL_FTP share seven characters: listed twice,
		// those would come up twice as often
		$all = implode('', array_unique(str_split($all)));
		
		$rest = $length - count($sets);
		if($rest > 0) // getBytesFromString() refuses a length of 0
		{
			$password.= $randomizer->getBytesFromString($all, $rest);
		}
		
		$password = $randomizer->shuffleBytes($password);
		
		if($dashes === false)
		{
			return $password;
		}
		
		$dashesCount = (int)floor(sqrt($length));
		$passwordDashed = '';
		while(strlen($password) > $dashesCount)
		{
			$passwordDashed.= substr($password, 0, $dashesCount) . '-';
			$password = substr($password, $dashesCount);
		}
		$passwordDashed.= $password;
		
		return $passwordDashed;
	}
}
