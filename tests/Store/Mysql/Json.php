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
	 * @var PDO 
	 */
	protected PDO $_source;
	
	/**
	 * https://bugs.mysql.com/bug.php?id=98135
	 * 
	 * @return bool
	 */
	public function mysqlBug(): bool
	{
		$store = (new class() extends Store
		{
			public const TABLE = 'tests';
		});
		$model = new class() extends Model
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
		$model::$store = $store;	
	
		$store->source()->exec('
			CREATE TABLE IF NOT EXISTS tests (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				object JSON NOT NULL,
				PRIMARY KEY (id)
			)	
			ENGINE = InnoDB;		
		');
		
		$url = 'https://test.com';
		$object = new stdClass;
		$object->url = $url;
		$object->array = [1, 2, 3];
		
		$modelInstance = new $model;
		$modelInstance->object = $object;
		$modelInstance->save();
		$modelInstance->refresh();
		$modelInstance->object = $object;
		
		$store->source()->exec('DROP TABLE IF EXISTS tests');
		
		return $modelInstance->isModified() === false;
	}
}
