<?php
declare(strict_types=1);

namespace Ovos;

/**
 * Encryptor
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Encryptor
{
	/**
	 * @var null|string
	 */
	protected $_method;

	/**
	 * @var string
	 */
	protected $_key;

	/**
	 * @param string $key
	 * @param null|string $method (optional)
	 */
	public function __construct(string $key, string $method = null)
	{
		$this->setKey($key);
		$this->setMethod($method);
	}

	/**
	 * @param null|string $method
	 * 
	 * @return $this
	 */
	public function setMethod(?string $method): self
	{
		$this->_method = $method;
		
		return $this;
	}

	/**
	 * @return null|string
	 */
	public function getMethod(): ?string
	{
		return $this->_method;
	}
	
	/**
	 * @param string $key
	 * 
	 * @return $this
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
	 * @param string $string
	 * @param null|string $method
	 * 
	 * @return null|string
	 */
	public function encrypt($string, ?string $method = null): ?string
	{
		if($string === null)
		{
			return null;
		}
		
		if($this->getMethod() === null && $method === null)
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
	 * @param string $string
	 * 
	 * @return null|string
	 */
	public function decrypt($string): ?string
	{
		if($string === null)
		{
			return null;
		}
		
		$string = base64_decode($string);
		list($method, $cipherKey, $cipherIv, $tag, $string) = explode(':', $string);	
	
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
	public function encryptArray($patterns, &$data)
	{
		$encryptor = new Encryptor($config->getKey(), $config->getMethod());
	
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
					$value = $encryptor->encrypt($value);
					
					break;
				}
			}	
		}
		
		return $data;
	}
		
}
