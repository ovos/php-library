<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Container\Inject;
use Ovos\Exception\NotFoundException;
use PDO;
use ReflectionClass;

use function file_exists;
use function file_get_contents;
use function sprintf;
use function substr;

/**
 * Migration
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Migration
{
	/**
	 * Container
	 *
	 * @var Container
	 */
	#[Inject]
	protected Container $_container;
	
	/**
	 * Application
	 *
	 * @var Application
	 */
	#[Inject]
	protected Application $_app;
	
	/**
	 * Config
	 *
	 * @var ArrayObject
	 */
	#[Inject]
	protected ArrayObject $_config;
	
	/**#@+
	 * Directions
	 */
	public const string DIRECTION_UP = 'up';
	public const string DIRECTION_DOWN = 'down';
	/**#@-*/
	
	/**
	 * @var ReflectionClass
	 */
	public ReflectionClass $class;
	
	/**
	 * @var string
	 */
	protected string $_sourceName = 'database';
	
	/**
	 * A connection between PHP and a database server
	 *
	 * @var ?PDO
	 */
	protected ?PDO $_source = null;
	
	/**
	 * @param ReflectionClass $class
	 */
	public function __construct(ReflectionClass $class)
	{
		$this->class = $class;
	}
	
	/**
	 * @return PDO
	 */
	public function getSource(): PDO
	{
		if($this->_source === null)
		{
			// get database connection
			$this->_source = $this->_container
				->getClass(Connections::class)
				->get($this->_sourceName)
				->getConnectedClient();
		}
		
		return $this->_source;
	}
	
	/**
	 * Short for getSource
	 *
	 * @return PDO
	 */
	public function source(): PDO
	{
		return $this->getSource();
	}
	
	abstract public function up(): void;
	abstract public function down(): void;
	
	/**
	 * @return int|false
	 *
	 * @throws NotFoundException
	 */
	public function upSql(): int|false
	{
		return $this->runSql(self::DIRECTION_UP);
	}
	
	/**
	 * @return int|false
	 *
	 * @throws NotFoundException
	 */
	public function downSql(): int|false
	{
		return $this->runSql(self::DIRECTION_DOWN);
	}
	
	/**
	 * @param string $suffix
	 *
	 * @return int|false
	 * @throws NotFoundException
	 */
	public function runSql(string $suffix): int|false
	{
		$sqlFile = $this->getSqlFilepath($suffix);
		$sql = file_get_contents($sqlFile);
		
		if(empty($sql))
		{
			return false;
		}
		
		$source = $this->source();
		
		$source->beginTransaction();
		$result = $source->exec($sql);
		if($source->inTransaction()) // e.g., CREATE TABLE does not start a transaction in MySQL
		{
			$source->commit();
		}
		
		return $result;
	}
	
	/**
	 * @param string $suffix
	 *
	 * @return ?string
	 */
	public function getSqlFilepath(string $suffix): ?string
	{
		$sqlFile = substr($this->class->getFileName(), 0, -4)
			. sprintf('_%s.sql', $suffix);
		
		if(file_exists($sqlFile) === false)
		{
			throw new NotFoundException('File %s not found.', $sqlFile);
		}
		
		return $sqlFile;
	}
}
