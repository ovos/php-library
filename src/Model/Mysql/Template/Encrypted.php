<?php
declare(strict_types=1);

namespace Ovos\Model\Mysql\Template;

use Ovos\ArrayObject;
use Ovos\Model\Mysql;
use Ovos\Model\Mysql\Template;
use Override;

use function bin2hex;
use function openssl_cipher_iv_length;
use function openssl_decrypt;
use function openssl_encrypt;
use function random_bytes;
use const OPENSSL_RAW_DATA;

/**
 * Encrypted
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Encrypted extends Template
{
	/**
	 * @var array
	 */
	protected array $_properties = [];
	
	/**
	 * @param array $properties
	 */
	public function __construct(array $properties = [])
	{
		parent::__construct();
		
		$this->setProperties($properties);
	}
	
	/**
	 * @param array $properties
	 * 
	 * @return static
	 */
	public function setProperties(array $properties): static
	{
		$this->_properties = $properties;
		
		return $this;
	}
	
	/**
	 * @return array
	 */
	public function getProperties(): array
	{
		return $this->_properties;
	}
	
	/**
	 * @param Mysql $model
	 */
	#[Override]
	public function setUp(Mysql $model): void
	{
		foreach($this->getProperties() as $property)
		{
			$model->addManipulators($property,
				[$this, 'decrypt'],
				[$this, 'encrypt'], 
				true
			);
		}
	}
	
	/**
	 * @return ?ArrayObject
	 */
	public function getEncryptionConfig(): ?ArrayObject
	{
		return $this->_config->database->encryption;
	}
	
	/**
	 * @param ?string $string
	 * @param string $property
	 * @param Mysql $model
	 *
	 * @return ?string
	 */
	public function encrypt(?string $string, string $property, Mysql $model): ?string
	{
		if($string === null)
		{
			return null;
		}
		
		if(($config = $this->getEncryptionConfig()) === null)
		{
			return null;
		}
		
		if($model->cipher_key === null)
		{
			$model->cipher_key = bin2hex(random_bytes(16));
		}
		
		if($model->cipher_iv === null)
		{
			$length = openssl_cipher_iv_length($config->method);
			$model->cipher_iv = random_bytes($length);
		}
		
		$string = openssl_encrypt($string, 
			$config->method,
			$config->key . $model->cipher_key, 
			OPENSSL_RAW_DATA,
			$model->cipher_iv);
		
		return $string ?: null;	
	}
	
	/**
	 * @param ?string $string
	 * @param string $property
	 * @param Mysql $model
	 *
	 * @return ?string
	 */
	public function decrypt(?string $string, string $property, Mysql $model): ?string
	{
		if($string === null)
		{
			return null;
		}
		
		if(($config = $this->getEncryptionConfig()) === null)
		{
			return null;
		}
		
		$string = openssl_decrypt($string, 
			$config->method,
			$config->key . $model->cipher_key, 
			OPENSSL_RAW_DATA,
			$model->cipher_iv);
		
		return $string ?: null;
	}
}
