<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Password\Hash;

/**
 * Password
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Password
{
	/**#@+
	 * Generator sets
	 */
	public const SET_LOWERCASE = 1;
	public const SET_UPPERCASE = 2;
	public const SET_DIGITS = 4;
	public const SET_SPECIAL = 8;
	public const SET_SPECIAL_FTP = 16;
	/**#@-*/
	
	/** @var Hash */
	public static $hashInstance;
	
	/**
	 * @param string $password
	 * @param int $algorithm
	 * @param array $options
	 *
	 * @return bool|string
	 */
	public static function hash(string $password, int $algorithm = null, $options = null)
	{
		return self::getHashInstance()->hash($password, $algorithm, $options);
	}
	
	/**
	 * @param string $password
	 * @param int $algorithm
	 * @param array $options
	 *
	 * @return bool|string
	 */
	public static function needsRehash(string $password, int $algorithm = null, $options = null)
	{
		return self::getHashInstance()->needsRehash($password, $algorithm, $options);
	}
	
	/**
	 * @return Hash
	 */
	public static function getHashInstance(): Hash
	{
		if(self::$hashInstance === null)
		{
			self::$hashInstance = new Hash;
		}
		
		return self::$hashInstance;
	}

	/**
	 * @param string $password
	 * @param string $hash
	 *
	 * @return bool
	 */
	public static function verify(string $password, string $hash): bool
	{
		return password_verify($password, $hash);
	}

	/**
	 * Generates a strong password of N length containing at least one lower case letter,
	 * one uppercase letter, one digit, and one special character. The remaining characters
	 * in the password are chosen at random from those four sets.
	 *
	 * The available characters in each set are user friendly - there are no ambiguous
	 * characters such as i, l, 1, o, 0, etc. This, coupled with the $add_dashes option,
	 * makes it much easier for users to manually type or speak their passwords.
	 * Note: the $add_dashes option will increase the length of the password by
	 * floor(sqrt(N)) characters.
	 *
	 * Based on
	 * @see https://gist.github.com/tylerhall/521810
	 *
	 * @param int $length
	 * @param bool $dashes
	 * @param int $availableSets
	 *
	 * @return string
	 */
	public static function generate(int $length = 9,
		bool $dashes = true,
		int $availableSets = self::SET_LOWERCASE + self::SET_UPPERCASE + self::SET_DIGITS + self::SET_SPECIAL
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

		$all = '';
		$password = '';
		foreach($sets as $set)
		{
			$password.= $set[array_rand(str_split($set))];
			$all.= $set;
		}
		$all = str_split($all);

		for($i = 0; $i < $length - \count($sets); $i++)
		{
			$password.= $all[array_rand($all)];
		}

		$password = str_shuffle($password);

		if($dashes === false)
		{
			return $password;
		}

		$dashesCount = (int)floor(sqrt($length));
		$passwordDashed = '';
		while(\strlen($password) > $dashesCount)
		{
			$passwordDashed.= substr($password, 0, $dashesCount) . '-';
			$password = substr($password, $dashesCount);
		}
		$passwordDashed.= $password;

		return $passwordDashed;
	}
}
