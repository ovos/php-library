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
	protected static ?self $instance = null;
	
	public static function getInstance(): static
	{
		if(static::$instance === null)
		{
			static::$instance = new static;
		}
		
		return static::$instance;
	}
	
	/**
	 * Clears an old instance and creates a new one
	 */
	public static function newInstance(): static
	{
		static::$instance = null;
		
		return self::getInstance();
	}
}
