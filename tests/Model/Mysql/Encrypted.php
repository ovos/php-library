<?php
declare(strict_types=1);

namespace Tests\Model\Mysql;

use Models\UserLogin;
use Ovos\Test;
use Ovos\Test\Internal;
use Ovos\Model\Mysql as Model;
use Ovos\Model\Mysql\Template;
use Ovos\Store\Mysql as Store;
use Ovos\Strings;
use Override;
use Stores\UsersLogins;

/**
 * Encrypted
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Encrypted extends Test
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
				$this->addTemplate(new Template\Encrypted([
					'secret',
				]));
			}
		};
		$this->model::$store = $this->store;
		
		$this->store->source()->exec('
			CREATE TABLE IF NOT EXISTS tests (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				name VARCHAR(64) NULL,
				secret VARBINARY(80) NULL, -- +16
				cipher_key VARCHAR(32) NULL,
				cipher_iv VARBINARY(16) NULL,
				modified_at DATETIME NULL,
				active TINYINT UNSIGNED NOT NULL DEFAULT 1,
				PRIMARY KEY (id),
				INDEX modified_at (modified_at ASC),
				INDEX active (active ASC)
			)
			ENGINE = InnoDB;
		');
	}
	
	public function encrypt(): bool
	{
		$model = new $this->model;
		$model->name = Strings::random();
		$model->secret = $model->name;
		$model->save();
		
		return $model->name === $model->secret;
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
