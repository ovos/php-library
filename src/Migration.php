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
	 * A connection between PHP and a database server
	 */
	protected ?PDO $source = null;
	
	public function __construct(
		ReflectionClass $class,
	)
	{
		$this->class = $class;
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
		$sqlFile = substr($this->class->getFileName(), 0, -4)
			. sprintf('_%s.sql', $suffix);
		
		if(file_exists($sqlFile) === false)
		{
			throw new NotFoundException('File %s not found.', $sqlFile);
		}
		
		return $sqlFile;
	}
}
