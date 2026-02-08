<?php
declare(strict_types=1);

namespace Tests\Store\Mysql;

use Ovos\Test;
use Ovos\Test\Internal;
use Ovos\Model\Mysql as Model;
use Ovos\Store\Mysql as Store;
use Ovos\Model\Mysql\Template;
use Override;
use stdClass;

/**
 * Json
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Json extends Test
{
	protected object $store;
	
	protected object $model;
	
	public function __construct()
	{
		$this->store = (new class() extends Store
		{
			public const ?string TABLE = 'tests';
		});
		$this->model = new class() extends Model
		{
			public static object $store;
			
			public static function getStoreClass(): string
			{
				return self::$store::class;
			}
			
			public function setUp(): void
			{
				$this->addTemplate(new Template\Json(['object'], 
					Template\Json::TYPE_OBJECT),
				);
			}
		};
		$this->model::$store = $this->store;
		
		$this->store->source()->exec('
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
		$subObject = new stdClass;
		$subObject->name = 'test';
		$subObject->number = 1;
		$subObject->boolean = true;
		$subObject->array = ['first', 'second'];
		
		$object = new stdClass;
		$object->url = 'https://test.com';
		$object->array = [1, 2, 3];
		$object->subObject = $subObject;
		
		$modelInstance = new $this->model;
		$modelInstance->object = $object;
		$modelInstance->save();
		$modelInstance->refresh();
		$modelInstance->object = $object;
		
		return $modelInstance->isModified() === false;
	}
	
	/**
	 * Called by the runner after each test method
	 */
	#[Internal]
	#[Override]
	public function finalize(): void
	{
		$this->store->source()
			->exec('TRUNCATE TABLE tests');
	}
	
	/**
	 * Called by the runner after all test methods have been invoked
	 */
	#[Internal]
	#[Override]
	public function deconstruct(): void
	{
		$this->store->source()
			->exec('DROP TABLE IF EXISTS tests');
	}
}
