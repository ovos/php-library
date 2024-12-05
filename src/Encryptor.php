<?php
declare(strict_types=1);

namespace Ovos;

use function base64_decode;
use function base64_encode;
use function bin2hex;
use function implode;
use function openssl_cipher_iv_length;
use function openssl_decrypt;
use function openssl_encrypt;
use function preg_match;
use function random_bytes;

/**
 * Encryptor
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Encryptor
{
	/**
	 * @var ?string
	 */
	protected ?string $_method;
	
	/**
	 * @var string
	 */
	protected string $_key;
	
	/**
	 * @param string $key
	 * @param ?string $method (optional)
	 */
	public function __construct(string $key, ?string $method = null)
	{
		$this->setKey($key);
		$this->setMethod($method);
	}
	
	/**
	 * @param ?string $method
	 * 
	 * @return self
	 */
	public function setMethod(?string $method): self
	{
		$this->_method = $method;
		
		return $this;
	}
	
	/**
	 * @return ?string
	 */
	public function getMethod(): ?string
	{
		return $this->_method;
	}
	
	/**
	 * @param string $key
	 * 
	 * @return self
	 */
	public function setKey($key): self
	{
		$this->_key = $key;
		
		return $this;
	}
	
	/**
	 * @return string
	 */
	public function getKey(): string
	{
		return $this->_key;
	}
	
	/**
	 * @param ?string $string
	 * @param ?string $method
	 * 
	 * @return ?string
	 */
	public function encrypt(?string $string, ?string $method = null): ?string
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
		
		$string = implode(':', [
			$this->getMethod(),
			$cipherKey,
			$cipherIv,
			$tag,
			$string,
		]);
		
		return $string ? base64_encode($string) : null;
	}
	
	/**
	 * @param ?string $string
	 * 
	 * @return ?string
	 */
	public function decrypt(?string $string): ?string
	{
		if($string === null)
		{
			return null;
		}
		
		$string = base64_decode($string);
		[$method, $cipherKey, $cipherIv, $tag, $string] = explode(':', $string);
		
		$string = openssl_decrypt($string, 
			$method,
			$this->getKey() . $cipherKey, 
			OPENSSL_RAW_DATA,
			$cipherIv,
			$tag
		);
		
		return $string ?: null;
	}
	
	/**
	 * @param array $patterns
	 * @param array $data
	 * 
	 * @return array
	 */
	public function encryptArray(array $patterns, array &$data): array
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
