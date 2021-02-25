<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Exception\NotFoundException;
use PDO;
use ReflectionClass;

/**
 * Migration
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Migration
{
	/**#@+
	 * Direction
	 */
	public const DIRECTION_UP = 'up';
	public const DIRECTION_DOWN = 'down';
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
	 * @var PDO
	 */
	protected null|PDO $_source = null;
	
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
			$this->_initSource();
		}

		return $this->_source;
	}

	/**
	 */
	public function _initSource()
	{
		// get database connection
		$this->_source = services()->database->get($this->_sourceName);
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
	
	public abstract function up(): void;
	public abstract function down(): void;

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
		
		return $this->source()->exec($sql);
	}

	/**
	 * @param string $suffix
	 *
	 * @return null|string
	 */
	public function getSqlFilepath(string $suffix): ?string
	{
		$sqlFile = substr($this->class->getFileName(), 0, -4) . sprintf('_%s.sql', $suffix);
		
		if(file_exists($sqlFile) === false)
		{
			throw new NotFoundException('File %s not found.', $sqlFile);
		}
		
		return $sqlFile;
	}
}
