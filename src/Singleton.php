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
	protected static $_instance;

	/**
	 * @return self
	 */
	public static function getInstance(): self
	{
		if(self::$_instance === null)
		{
			self::$_instance = new self;
		}

		return self::$_instance;
	}
}
