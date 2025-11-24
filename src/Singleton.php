<?php
declare(strict_types=1);

namespace Ovos;

/**
 * Singleton
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
trait Singleton
{
	/**
	 * @var ?self
	 */
	protected static ?self $_instance = null;
	
	/**
	 * @return static
	 */
	public static function getInstance(): static
	{
		if(static::$_instance === null)
		{
			static::$_instance = new static;
		}
		
		return static::$_instance;
	}
	
	/**
	 * Clears an old instance and creates a new one
	 *
	 * @return static
	 */
	public static function newInstance(): static
	{
		static::$_instance = null;
		
		return self::getInstance();
	}
}
