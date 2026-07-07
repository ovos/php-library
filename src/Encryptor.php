<?php
declare(strict_types=1);

namespace Ovos;

use function array_map;
use function base64_decode;
use function base64_encode;
use function bin2hex;
use function count;
use function explode;
use function implode;
use function openssl_cipher_iv_length;
use function openssl_decrypt;
use function openssl_encrypt;
use function preg_match;
use function random_bytes;

/**
 * Encryptor
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Encryptor
{
	protected ?string $method;
	
	protected string $key;
	
	public function __construct(
		string $key,
		?string $method = null,
	)
	{
		$this->setKey($key);
		$this->setMethod($method);
	}
	
	public function setMethod(
		?string $method,
	): static
	{
		$this->method = $method;
		
		return $this;
	}
	
	public function getMethod(): ?string
	{
		return $this->method;
	}
	
	public function setKey(
		string $key,
	): static
	{
		$this->key = $key;
		
		return $this;
	}
	
	public function getKey(): string
	{
		return $this->key;
	}
	
	public function encrypt(
		?string $string,
		?string $method = null,
	): ?string
	{
		if($string === null)
		{
			return null;
		}
		
		if($method === null && $this->getMethod() === null)
		{
			return null;
		}
		
		$cipherKey = bin2hex(random_bytes(16));
		$length = openssl_cipher_iv_length($this->getMethod());
		$cipherIv = random_bytes($length);
		
		$string = openssl_encrypt($string, 
			$this->getMethod(),
			$this->getKey() . $cipherKey, 
			OPENSSL_RAW_DATA,
			$cipherIv, 
			$tag
		);
		
		if($string === false)
		{
			return null;
		}
		
		// base64 each field before joining: the IV, tag and ciphertext are
		// raw binary and routinely contain the ':' delimiter byte, which used
		// to make decrypt()'s explode(':') mis-split and corrupt ~44% of
		// payloads. The base64 alphabet never contains ':'.
		return base64_encode(implode(':', array_map(base64_encode(...), [
			$this->getMethod(),
			$cipherKey,
			$cipherIv,
			$tag,
			$string,
		])));
	}
	
	public function decrypt(
		?string $string,
	): ?string
	{
		if($string === null)
		{
			return null;
		}
		
		$parts = explode(':', (string)base64_decode($string));
		if(count($parts) !== 5)
		{
			return null; // not a payload this class produced
		}
		
		[$method, $cipherKey, $cipherIv, $tag, $string] = array_map(
			static fn(string $part): string => (string)base64_decode($part),
			$parts,
		);
		
		$string = openssl_decrypt($string,
			$method,
			$this->getKey() . $cipherKey,
			OPENSSL_RAW_DATA,
			$cipherIv,
			$tag
		);
		
		// openssl_decrypt returns false on failure; a decrypted empty string
		// is a valid result and must not be coerced to null
		return $string === false ? null : $string;
	}
	
	public function encryptArray(
		array $patterns,
		array &$data,
	): array
	{
		foreach($data as $key => &$value)
		{
			if(is_array($value))
			{
				$value = $this->encryptArray($patterns, $value);
				
				continue;
			}
			
			foreach($patterns as $pattern)
			{
				if(preg_match($pattern, $key, $matches))
				{
					$value = $this->encrypt($value);
					
					break;
				}
			}
		}
		
		return $data;
	}
}
