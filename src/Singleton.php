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
	 * @var self
	 */
	protected static null|self $_instance = null;

	/**
	 * @return self
	 */
	public static function getInstance(): self
	{
		if(static::$_instance === null)
		{
			static::$_instance = new static;
		}

		return static::$_instance;
	}

	/**
	 * Clears old instance and creates a new one
	 * 
	 * @return self
	 */
	public static function newInstance(): self
	{
		static::$_instance = null;
		
		return self::getInstance();
	}
}
