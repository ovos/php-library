<?php
declare(strict_types=1);

namespace Tests;

use Ovos\Model as BaseModel;
use Ovos\Test;

use function array_key_exists;

/**
 * Model
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Model extends Test
{
	protected object $model;
	
	public function __construct()
	{
		$this->model = new class() extends BaseModel
		{
			public string $name = '';
			
			public int $value = 0;
			
			public static function getStoreClass(): string
			{
				return '';
			}
			
			public function getContainerInjected(): bool
			{
				return isset($this->container);
			}
			
			public function getAppInjected(): bool
			{
				return isset($this->app);
			}
			
			public function getConfigInjected(): bool
			{
				return isset($this->config);
			}
		};
	}
	
	/**
	 * __serialize must strip the injected dependencies
	 * (container, app, config), otherwise serializing a Model
	 * pulls the entire DI graph into the payload.
	 */
	public function serializeStripsInjected(): bool
	{
		$model = new $this->model;
		$serialized = $model->__serialize();
		
		return array_key_exists('container', $serialized) === false
			&& array_key_exists('app', $serialized) === false
			&& array_key_exists('config', $serialized) === false;
	}
	
	/**
	 * __serialize must keep the data properties of the model,
	 * only the injected dependencies are excluded.
	 */
	public function serializeKeepsData(): bool
	{
		$model = new $this->model;
		$model->name = 'serialized';
		$model->value = 42;
		
		$serialized = $model->__serialize();
		
		return ($serialized['name'] ?? null) === 'serialized'
			&& ($serialized['value'] ?? null) === 42;
	}
	
	/**
	 * __unserialize must re-inject container, app and config,
	 * otherwise the restored model has unitialized typed properties.
	 */
	public function unserializeRestoresInjected(): bool
	{
		$model = new $this->model;
		$model->__unserialize([
			'name' => 'restored',
			'value' => 7,
		]);
		
		return $model->getContainerInjected()
			&& $model->getAppInjected()
			&& $model->getConfigInjected();
	}
	
	/**
	 * Regression test: without the data-copy loop in __unserialize
	 * the restored model was empty - the payload was discarded.
	 */
	public function unserializeRestoresData(): bool
	{
		$model = new $this->model;
		$model->__unserialize([
			'name' => 'restored',
			'value' => 7,
		]);
		
		return $model->name === 'restored'
			&& $model->value === 7;
	}
	
	/**
	 * Regression test: full __serialize -> __unserialize round-trip
	 * must preserve data. Without the bug-fix the rehydrated model
	 * was empty.
	 */
	public function roundtrip(): bool
	{
		$model = new $this->model;
		$model->name = 'roundtrip';
		$model->value = 99;
		
		$serialized = $model->__serialize();
		
		$rehydrated = new $this->model;
		$rehydrated->__unserialize($serialized);
		
		return $rehydrated->name === 'roundtrip'
			&& $rehydrated->value === 99
			&& $rehydrated->getContainerInjected()
			&& $rehydrated->getAppInjected()
			&& $rehydrated->getConfigInjected();
	}
}
