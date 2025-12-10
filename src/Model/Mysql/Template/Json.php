<?php
declare(strict_types=1);

namespace Ovos\Model\Mysql\Template;

use Ovos\Exception;
use Ovos\Model\Mysql;
use Ovos\Model\Mysql\Template;
use PDO;
use stdClass;
use Closure;

use function is_string;
use function json_decode;
use function json_encode;
use function str_replace;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;
use const JSON_UNESCAPED_SLASHES;
use const JSON_NUMERIC_CHECK;

/**
 * Json
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Json extends Template
{
	// Types
	public const string TYPE_ARRAY = 'array';
	public const string TYPE_OBJECT = 'object';
	
	protected array $properties = [];
	
	/**
	 * Type of stored object
	 */
	protected string $type;
	
	/**
	 * Class of stored object
	 */
	protected null|string|Closure $class = null;
	
	public function __construct(
		array $properties = [],
		string $type = self::TYPE_ARRAY,
		null|string|Closure $class = null
	)
	{
		parent::__construct();
	
		$this->properties = $properties;
		$this->type = $type;
		$this->class = $class;
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
	
	public function setType(
		string $type,
	): static
	{
		$this->type = $type;
		
		return $this;
	}
	
	public function getType(): string
	{
		return $this->type;
	}
	
	public function setClass(
		null|string|Closure $class,
	): static
	{
		$this->class = $class;
		
		return $this;
	}
	
	public function getClass(
	): null|string|Closure
	{
		return $this->class;
	}
	
	public function setUp(
		Mysql $model,
	): void
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
	
	public function encode(
		mixed $object,
		string $property,
		Mysql $model,
	): ?string
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
		
		// escape backslashes
		// https://stackoverflow.com/questions/74481967/mysql-valid-json-causes-missing-a-comma-or-after-an-object-member
		$string = str_replace('\\', '\\\\', $string);
		// single APOS character causes SQL error, for example, so it needs to be escaped:
		// SELECT CAST('{"name":"\\"Harry's Gastrotainment Harald Schindlegger\\" e.U."}' as JSON);
		$string = str_replace('\'', '\\\'', $string);
		
		// compatibility with MySQL format, @see https://bugs.mysql.com/bug.php?id=98135
		$query = $model
			->source()
			->query('SELECT CAST(\'' . $string . '\' as JSON)',
				PDO::FETCH_COLUMN,
				0,
			);
		$string = $query->fetch();
		
		return $string ?: null;
	}
	
	public function decode(
		string $string,
		string $property,
		Mysql $model,
	): mixed
	{
		$object = json_decode($string,
			associative: $this->type === self::TYPE_ARRAY,
			flags: JSON_THROW_ON_ERROR
		);
		
		if($object === null)
		{
			return null;
		}
		
		if($this->type === self::TYPE_ARRAY
			&& is_array($object))
		{
			return $object;
		}
		
		if($this->type === self::TYPE_ARRAY)
		{
			return (array)$object;
		}
		
		// restore class of object
		if($this->class !== null)
		{
			$class = $this->class instanceof Closure
				? call_user_func($this->class)
				: $this->class;
			
			$object = $class::import($object, exists: true);
		}
		
		return $object;
	}
}
