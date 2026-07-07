<?php
declare(strict_types=1);

namespace Tests;

use Ovos\Encryptor as Subject;
use Ovos\Test;

use function bin2hex;
use function random_bytes;
use function str_repeat;

/**
 * Encryptor
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Encryptor extends Test
{
	protected function encryptor(): Subject
	{
		return new Subject(bin2hex(random_bytes(16)), 'AES-128-GCM');
	}
	
	/**
	 * The IV, tag and ciphertext are raw binary and routinely contain the
	 * ':' delimiter byte; decrypt() must not mis-split on them. Fuzzed over
	 * many fresh IVs, EVERY roundtrip has to survive.
	 */
	public function roundtripSurvivesBinaryDelimiterCollisions(): bool
	{
		$encryptor = $this->encryptor();
		
		for($i = 0; $i < 300; $i++)
		{
			$plain = bin2hex(random_bytes(48));
			
			if($encryptor->decrypt($encryptor->encrypt($plain)) !== $plain)
			{
				return false;
			}
		}
		
		return true;
	}
	
	public function roundtripsUnicodeAndEmpty(): bool
	{
		$encryptor = $this->encryptor();
		
		$cases = ['', 'ascii', 'zażółć gęślą jaźń', '🔐 emoji', str_repeat('x', 5000)];
		foreach($cases as $plain)
		{
			if($encryptor->decrypt($encryptor->encrypt($plain)) !== $plain)
			{
				return false;
			}
		}
		
		return true;
	}
	
	public function nullPassesThrough(): bool
	{
		$encryptor = $this->encryptor();
		
		return $encryptor->encrypt(null) === null
			&& $encryptor->decrypt(null) === null;
	}
	
	public function noMethodYieldsNull(): bool
	{
		// a key but no cipher method configured
		$encryptor = new Subject(bin2hex(random_bytes(16)));
		
		return $encryptor->encrypt('secret') === null;
	}
	
	public function corruptPayloadDecryptsToNullNotError(): bool
	{
		$encryptor = $this->encryptor();
		
		// garbage that is not a well-formed payload must be handled, not fatal
		return $encryptor->decrypt('not-a-valid-payload') === null
			&& $encryptor->decrypt(base64_encode('too:few:parts')) === null;
	}
}
