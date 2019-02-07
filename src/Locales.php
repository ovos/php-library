<?php

namespace Ovos;

/**
 * Locales
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Locales
{
	/**
	 * @var Locale
	 */
	protected static $_default;

	/**
	 * @var array
	 */
	protected static $_instances;

	/**
	 * @var array
	 */
	protected static $_all;

	/**
	 *
	 * @return ArrayObject
	 */
	public static function getConfigs(): ArrayObject
	{
		return app()->getConfig()->system->locales;
	}

	/**
	 * @param string $symbol
	 *
	 * @return bool
	 */
	public static function exists(string $symbol): bool
	{
		return self::getConfigs()->offsetExists($symbol);
	}

	/**
	 * @param string $symbol
	 *
	 * @return Locale
	 */
	public static function create(string $symbol): Locale
	{
		$localeClassNs = 'Locales\\' . $symbol;
		$locale = class_exists($localeClassNs) ?
			new $localeClassNs
			: new Locale;

		$locale->setSymbol($symbol);

		$configs = self::getConfigs();
		if($configs->offsetExists($symbol))
		{
			$config = $configs->offsetGet($symbol);
			if($config->default)
			{
				$locale->setDefault($config->default);
			}
			if($config->name)
			{
				$locale->setName($config->name);
			}
		}

		return $locale;
	}

	/**
	 * @param string $symbol
	 *
	 * @return Locale
	 */
	public static function get(string $symbol): Locale
	{
		if(!isset(self::$_instances[$symbol]))
		{
			self::$_instances[$symbol] = self::create($symbol);
		}

		return self::$_instances[$symbol];
	}

	/**
	 * @return Locale[]
	 */
	public static function getAll(): array
	{
		if(self::$_all === null)
		{
			foreach(self::getConfigs() as $symbol => $config)
			{
				self::$_all[$symbol] = self::get($symbol);
			}
		}

		return self::$_all;
	}

	/**
	 * @return Locale
	 */
	public static function getDefault(): Locale
	{
		$all = self::getAll();

		foreach($all as $locale)
		{
			if($locale->isDefault())
			{
				return $locale;
			}
		}

		return self::get(Locale::DEFAULT);
	}
}
