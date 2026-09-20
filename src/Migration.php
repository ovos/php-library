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
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Migration
{
	#[Inject]
	protected Container $container;
	
	#[Inject]
	protected Application $app;
	
	#[Inject]
	protected ArrayObject $config;
	
	// Directions
	public const string DIRECTION_UP = 'up';
	public const string DIRECTION_DOWN = 'down';
	
	public ReflectionClass $class;
	
	protected string $sourceName = 'mysql';
	
	/**
	 * Where this migration's SQL halves live, without the `_up.sql` /
	 * `_down.sql` tail. Null for a migration that has a class of its own:
	 * the class's file name IS the base, which is how every migration
	 * worked before a migration could be SQL alone.
	 */
	protected ?string $sqlBase = null;
	
	/**
	 * A connection between PHP and a database server
	 */
	protected ?PDO $source = null;
	
	public function __construct(
		ReflectionClass $class,
		?string $sqlBase = null,
	)
	{
		$this->class = $class;
		$this->sqlBase = $sqlBase;
	}
	
	public function getSource(): PDO
	{
		if($this->source === null)
		{
			// get database connection
			$this->source = $this->container
				->getClass(Connections::class)
				->get($this->sourceName)
				->getClient();
		}
		
		return $this->source;
	}
	
	/**
	 * Short for getSource
	 */
	public function source(): PDO
	{
		return $this->getSource();
	}
	
	abstract public function up(): void;
	abstract public function down(): void;
	
	/**
	 * @throws NotFoundException
	 */
	public function upSql(): int|false
	{
		return $this->runSql(self::DIRECTION_UP);
	}
	
	/**
	 * @throws NotFoundException
	 */
	public function downSql(): int|false
	{
		return $this->runSql(self::DIRECTION_DOWN);
	}
	
	/**
	 * @throws NotFoundException
	 */
	public function runSql(
		string $suffix,
	): int|false
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
	
	public function getSqlFilepath(
		string $suffix,
	): ?string
	{
		// a SQL-only migration is told where its halves are; one with a
		// class of its own is named after that class's file
		$sqlFile = ($this->sqlBase ?? substr($this->class->getFileName(), 0, -4))
			. sprintf('_%s.sql', $suffix);
		
		if(file_exists($sqlFile) === false)
		{
			throw new NotFoundException('File %s not found.', $sqlFile);
		}
		
		return $sqlFile;
	}
}
