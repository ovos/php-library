<?php
declare(strict_types=1);

namespace Tests\Store;

use Ovos\Test;
use Ovos\Store\Mysql as Store;
use Ovos\Pdo\Profiler\Reporter;
use PDO;

/**
 * Mysql
 *
 * @package Tests
 * @author Marcin Gil <mg@ovos.at>
 */
class Mysql extends Test
{
	/**
	 * @var object
	 */
	protected object $_store;

	public function __construct()
	{
		$this->_store = (new class() extends Store
		{
			public const TABLE = 'tests';
		});
		
		$this->_store->source()->exec('
			CREATE TABLE IF NOT EXISTS tests (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				name VARCHAR(128) NULL,
				created_at DATETIME NOT NULL,
				modified_at DATETIME NULL,
				active TINYINT UNSIGNED NOT NULL DEFAULT 1,
				PRIMARY KEY (id),
				INDEX created_at (created_at ASC),
				INDEX modified_at (modified_at ASC),
				INDEX active (active ASC)
			)
			ENGINE = InnoDB;
		');
	}

	public function reporter(): bool
	{
		$this->_store->source()->exec('
			INSERT INTO tests
			VALUES
				(1, "Test 1", NOW(), null, 1),
				(2, "Test 2", NOW(), null, 1),  
				(3, "Test 3", NOW(), null, 0);
		');
		
		$this->_store->executeFind(whereIn: ['active' => [1]]);
		
		$reporter = new Reporter;
		return count($reporter->getReport()) > 0;
	}
	
	/**
	 * Called by runner after the test method was called
	 */
	public function cleanUp()
	{
		$this->_store->source()->exec('DROP TABLE IF EXISTS tests');
	}
}
