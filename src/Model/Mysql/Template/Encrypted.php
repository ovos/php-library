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
	protected array $properties = [];
	
	public function __construct(
		array $properties = [],
	)
	{
		parent::__construct();
		
		$this->setProperties($properties);
	}
	
	public function setProperties(
		array $properties,
	): static
	{
		$this->properties = $properties;
		
		return $this;
	}
	
	public function getProperties(): array
	{
		return $this->properties;
	}
	
	#[Override]
	public function setUp(
		Mysql $model,
	): void
	{
		foreach($this->getProperties() as $property)
		{
			$model->addManipulators($property,
				[$this, 'decrypt'],
				[$this, 'encrypt'],
				true,
			);
		}
	}
	
	public function getEncryptionConfig(): ?ArrayObject
	{
		return $this->config->database->encryption;
	}
	
	public function encrypt(
		?string $string,
		string $property,
		Mysql $model,
	): ?string
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
	
	public function decrypt(
		?string $string,
		string $property,
		Mysql $model,
	): ?string
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
