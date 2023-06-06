<?php
declare(strict_types=1);

namespace Ovos\Model\Mysql\Template;

use Ovos\ArrayObject;
use Ovos\Exception;
use Ovos\Model\Mysql;
use Ovos\Model\Mysql\Template;
use Ovos\Pdo\Expression;
use PDO;
use stdClass;

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
	 * @var array
	 */
	protected array $_properties = [];
	
	/**
	 * Type of stored object
	 * 
	 * @var string
	 */
	protected string $_type;
		
	/**
	 * Class of stored object
	 * 
	 * @var ?string
	 */
	protected ?string $_class = null;

	/**
	 * @param array $properties
	 */
	public function __construct(
		array $properties = [],
		$type = self::TYPE_ARRAY,
		$class = null
	)
	{
		parent::__construct();
	
		$this->_properties = $properties;
		$this->_type = $type;
		$this->_class = $class;
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
	 * @param string $class
	 * 
	 * @return self
	 */
	public function setClass(string $class): self
	{
		$this->_class = $class;
		
		return $this;
	}
	
	/**
	 * @return string
	 */
	public function getClass(): string
	{
		return $this->_class;
	}

	/**
	 * @param Mysql $model
	 */
	public function setUp(Mysql $model): void
	{
		foreach($this->getProperties() as $property)
		{
			$model->addManipulators($property,
				[$this, 'decode'],
				[$this, 'encode'],
				true
			);
		}
	}
	
	/**
	 * @param mixed $object
	 * @param string $property
	 * @param Mysql $model
	 * 
	 * @return ?string
	 */
	public function encode(mixed $object, string $property, Mysql $model): ?string
	{
		if($object === null)
		{
			return null;
		}
		
		if(is_string($object))
		{
			throw new Exception('Cannot encode a string.');
		}
	
		$string = json_encode($object, JSON_THROW_ON_ERROR
			| JSON_UNESCAPED_UNICODE
			| JSON_UNESCAPED_SLASHES
		 	| JSON_NUMERIC_CHECK
		);
		
		// https://stackoverflow.com/questions/74481967/mysql-valid-json-causes-missing-a-comma-or-after-an-object-member
		$string = str_replace('\\', '\\\\', $string);
		
		// compatibility with MySQL format, @see https://bugs.mysql.com/bug.php?id=98135
		$query = $model->source()->query('SELECT CAST(\'' . $string . '\' as JSON)', PDO::FETCH_COLUMN, 0);
		$string = $query->fetch();
		
		return $string ?: null;	
	}
	
	/**
	 * @param string $string
	 * @param string $property
	 * @param Mysql $model
	 * 
	 * @return null|array|stdClass
	 */
	public function decode(string $string, string $property, Mysql $model): mixed
	{
		if($string === null)
		{
			return null;
		}
		
		$object = json_decode($string,
			associative: $this->_type === self::TYPE_ARRAY,
			flags: JSON_THROW_ON_ERROR
		);
		
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
		
		// restore class of object
		if($this->_class !== null)
		{
			$object = $this->_class::import($object, exists: true);
		}
		
		return $object;
	}
}
