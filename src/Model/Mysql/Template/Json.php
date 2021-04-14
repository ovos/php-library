<?php
declare(strict_types=1);

namespace Ovos\Model\Mysql\Template;

use Ovos\ArrayObject;
use Ovos\Model\Mysql;
use Ovos\Model\Mysql\Template;
use Ovos\Pdo\Expression;

/**
 * Json
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Json extends Template
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
	 * @return self
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
			$model->addManipulators($property,'decode', 'encode');
		}
	}
	
	/**
	 * @param Mysql $model
	 * @param string $string
	 * 
	 * @return ?string
	 */
	public function encode(Mysql $model, string $string): ?string
	{
		if($string === null)
		{
			return null;
		}
	
		$string = json_encode($strng, JSON_THROW_ON_ERROR
			| JSON_UNESCAPED_UNICODE
			| JSON_UNESCAPED_SLASHES
		 	| JSON_NUMERIC_CHECK
			| JSON_PRETTY_PRINT
		);
			
		return $string ?: null;	
	}
	
	/**
	 * @param Mysql $model
	 * @param string $string
	 * 
	 * @return ?string
	 */
	public function decode(Mysql $model, string $string): ?string
	{
		if($string === null)
		{
			return null;
		}
		
		$string = json_decode($string, flags: JSON_THROW_ON_ERROR);
			
		return $string ?: null;	
	}
}
