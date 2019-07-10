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
	public static function getConfig(): ArrayObject
	{
		$locales = app()->getConfig()->system->locales;
		return $locales ? $locales : new ArrayObject;
	}

	/**
	 * @param string $urlName
	 *
	 * @return bool
	 */
	public static function exists(string $urlName): bool
	{
		return self::getConfig()->offsetExists($urlName);
	}

	/**
	 * @param string $urlName
	 *
	 * @return Locale
	 */
	public static function create(string $urlName): Locale
	{
		$configs = self::getConfig();
		if(($config = $configs->offsetGet($urlName)) === null
			|| $config->symbol === null)
		{
			return new Locale;
		}
	
		$localeClassNs = 'Locales\\' . $config->symbol;
		$locale = class_exists($localeClassNs) ?
			new $localeClassNs
			: new Locale;
		
		$locale->setUrlName($urlName);
		$locale->setSymbol($config->symbol);
		
		if($config->language)
		{
			$locale->setLanguage($config->language);
		}
		if($config->country)
		{
			$locale->setCountry($config->country);
		}
		if($config->name)
		{
			$locale->setName($config->name);
		}
		if($config->default)
		{
			$locale->setDefault($config->default);
		}

		return $locale;
	}

	/**
	 * @param string $urlName
	 *
	 * @return Locale
	 */
	public static function get(string $urlName): Locale
	{
		if(!isset(self::$_instances[$urlName]))
		{
			self::$_instances[$urlName] = self::create($urlName);
		}

		return self::$_instances[$urlName];
	}
	
	/**
	 * @return Locale[]
	 */
	public static function getAll(): array
	{
		if(self::$_all === null)
		{
			self::$_all = [];
			foreach(self::getConfig() as $urlName => $config)
			{
				self::$_all[$urlName] = self::get($urlName);
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
