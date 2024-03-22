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
	 * @var array
	 */
	protected static array $_instances;

	/**
	 * @var ?array
	 */
	protected static ?array $_all = null;
	
	/**
	 *
	 * @return ArrayObject
	 */
	public static function getConfig(): ArrayObject
	{
		$locales = app()->getConfig()->system->locales;
		return $locales ?: new ArrayObject;
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
	 * @param ?ArrayObject $config
	 *
	 * @return Locale
	 */
	public static function create(
		string $urlName,
		?ArrayObject $config = null,
	): Locale
	{
		if($config !== null)
		{
			return new Locale($urlName, $config);
		}
	
		$configs = self::getConfig();
		if(($config = $configs->offsetGet($urlName)) === null
			|| $config->symbol === null)
		{
			return new Locale($urlName);
		}
	
		$localeClassNs = 'Locales\\' . $config->symbol;
		$locale = class_exists($localeClassNs) ?
			new $localeClassNs($urlName)
			: new Locale($urlName);
		
		$locale->fromConfig($config);

		return $locale;
	}

	/**
	 * @param string $urlName
	 * @param ?ArrayObject $config
	 *
	 * @return Locale
	 */
	public static function get(
		string $urlName,
		?ArrayObject $config = null,
	): Locale
	{
		if(!isset(self::$_instances[$urlName]))
		{
			self::$_instances[$urlName] = self::create($urlName, $config);
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
		
		// we have to provide a config to the locale object,
		// `symbol` is required and `default` prevents from prepending locale to URLs
		return self::get(
		Locale::DEFAULT_URL_NAME, 
			ArrayObject::factory([
				'symbol' => Locale::DEFAULT_SYMBOL,
				'default' => true
			]),
		);
	}
}
