<?php
declare(strict_types=1);

namespace Ovos\Model\Mysql\Template;

use Ovos\Model\Mysql;
use Ovos\Model\Mysql\Template;
use Ovos\Pdo\Expression;

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
	protected $_properties = [];

	/**
	 * @param array $properties
	 */
	public function __construct($properties = [])
	{
		parent::__construct();
	
		$this->setProperties($properties);
	}

	/**
	 * @param array $properties
	 * 
	 * @return $this
	 */
	public function setProperties(array $properties): self
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
	public function setUp(Mysql $model): void
	{
		foreach($this->getProperties() as $property)
		{
			$model->addManipulators($property,'decrypt', 'encrypt');
		}
	}
	
	/**
	 * @param Mysql $model
	 * @param string $value
	 * 
	 * @return null|string
	 */
	public function encrypt(Mysql $model, $value): ?string
	{
		if($value === null)
		{
			return null;
		}
	
		if($model->cipher_key === null)
		{
			$model->cipher_key = bin2hex(random_bytes(16));
		}
		
		if($model->cipher_iv === null)
		{
			$length = openssl_cipher_iv_length($this->_config->database->encryption->method);
			$model->cipher_iv = random_bytes($length);
		}
	
		$value = openssl_encrypt($value, 
			$this->_config->database->encryption->method,
			$this->_config->database->encryption->key . $model->cipher_key, 
			OPENSSL_RAW_DATA,
			$model->cipher_iv);
			
		return $value ?: null;	
	}
	
	/**
	 * @param Mysql $model
	 * @param string $value
	 * 
	 * @return null|string
	 */
	public function decrypt(Mysql $model, $value): ?string
	{
		if($value === null)
		{
			return null;
		}	
	
		$value = openssl_decrypt($value, 
			$this->_config->database->encryption->method,
			$this->_config->database->encryption->key . $model->cipher_key, 
			OPENSSL_RAW_DATA,
			$model->cipher_iv);
			
		return $value ?: null;	
	}
}
