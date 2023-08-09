<?php
declare(strict_types=1);

namespace Tests\Store\Mysql;

use Ovos\Test;
use Ovos\Model\Mysql as Model;
use Ovos\Store\Mysql as Store;
use Ovos\Model\Mysql\Template;
use stdClass;

/**
 * Json
 *
 * @package Tests
 * @author Marcin Gil <mg@ovos.at>
 */
class Json extends Test
{
	/**
	 * @var object
	 */
	protected object $_store;
	
	/**
	 * @var object
	 */
	protected object $_model; 

	public function __construct()
	{
		$this->_store = (new class() extends Store
		{
			public const TABLE = 'tests';
		});
		$this->_model = new class() extends Model
		{
			public static $store;
			
			public static function getStoreClass(): string
			{
				return self::$store::class;
			}
			
			public function setUp(): void
			{
				$this->addTemplate(new Template\Json(['object'], Template\Json::TYPE_OBJECT));
			}
		};
		$this->_model::$store = $this->_store;
	
		$this->_store->source()->exec('
			CREATE TABLE IF NOT EXISTS tests (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				object JSON NOT NULL,
				PRIMARY KEY (id)
			)
			ENGINE = InnoDB;	
		');
	}
	
	/**
	 * https://bugs.mysql.com/bug.php?id=98135
	 * 
	 * @return bool
	 */
	public function mysqlBug(): bool
	{
		$url = 'https://test.com';
		$object = new stdClass;
		$object->url = $url;
		$object->array = [1, 2, 3];
		
		$modelInstance = new $this->_model;
		$modelInstance->object = $object;
		$modelInstance->save();
		$modelInstance->refresh();
		$modelInstance->object = $object;
		
		return $modelInstance->isModified() === false;
	}
	
	/**
	 * Called by runner after the test method was called
	 */
	public function cleanUp()
	{
		$this->_store->source()->exec('DROP TABLE IF EXISTS tests');
	}
}
