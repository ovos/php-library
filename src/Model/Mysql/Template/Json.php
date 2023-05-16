<?php
declare(strict_types=1);

namespace Ovos\Model\Mysql\Template;

use Ovos\ArrayObject;
use Ovos\Model\Mysql;
use Ovos\Model\Mysql\Template;
use Ovos\Pdo\Expression;
use PDO;

/**
 * Json
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Json extends Template
{
	/**#@+
	 * Types
	 * 
	 * @var string
	 */
	public const TYPE_ARRAY = 'array';
	public const TYPE_OBJECT = 'object';
	/**#@-*/

	/**
	 * Type of stored object
	 * 
	 * @var string
	 */
	protected string $_type;
	
	/**
	 * @var array
	 */
	protected array $_properties = [];

	/**
	 * @param array $properties
	 */
	public function __construct(array $properties = [], $type = self::TYPE_ARRAY)
	{
		parent::__construct();
	
		$this->_properties = $properties;
		$this->_type = $type;
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
	 * @param string $type
	 * 
	 * @return self
	 */
	public function setType(string $type): self
	{
		$this->_type = $type;
		
		return $this;
	}
	
	/**
	 * @return string
	 */
	public function getType(): string
	{
		return $this->_type;
	}

	/**
	 * @param Mysql $model
	 */
	public function setUp(Mysql $model): void
	{
		foreach($this->getProperties() as $property)
		{
			$model->addManipulators($property,'decode', 'encode', true);
		}
	}
	
	/**
	 * @param Mysql $model
	 * @param mixed $object
	 * 
	 * @return ?string
	 */
	public function encode(Mysql $model, mixed $object): ?string
	{
		if($object === null)
		{
			return null;
		}
	
		$string = json_encode($object, JSON_THROW_ON_ERROR
			| JSON_UNESCAPED_UNICODE
			| JSON_UNESCAPED_SLASHES
		 	| JSON_NUMERIC_CHECK
		);
		
		// compatibility with MySQL format, @see https://bugs.mysql.com/bug.php?id=98135
		$query = $model->source()->query('SELECT CAST(\'' . $string . '\' as JSON)', PDO::FETCH_COLUMN, 0);
		$string = $query->fetch();
		
		return $string ?: null;	
	}
	
	/**
	 * @param Mysql $model
	 * @param string $string
	 * 
	 * @return ?array
	 */
	public function decode(Mysql $model, string $string): mixed
	{
		if($string === null)
		{
			return null;
		}
		
		$object = json_decode($string, flags: JSON_THROW_ON_ERROR);
		
		if($object === null)
		{
			return null;
		}
		if($this->_type === self::TYPE_ARRAY
			&& is_array($object))
		{
			return $object;
		}
		
		if($this->_type === self::TYPE_ARRAY)
		{
			return (array)$object;
		}
		
		return $object;
	}
}
