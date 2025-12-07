<?php

namespace Ovos;

use function class_exists;

/**
 * Locales
 * TODO: rework for container
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Locales
{
	protected static array $instances;
	
	protected static ?array $all = null;
	
	public static function getConfig(): ArrayObject
	{
		$locales = app()->getConfig()->system->locales;
		return $locales ?: new ArrayObject;
	}
	
	public static function exists(
		string $urlName,
	): bool
	{
		return self::getConfig()->offsetExists($urlName);
	}
	
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
	
	public static function get(
		string $urlName,
		?ArrayObject $config = null,
	): Locale
	{
		if(!isset(self::$instances[$urlName]))
		{
			self::$instances[$urlName] = self::create($urlName, $config);
		}
		
		return self::$instances[$urlName];
	}
	
	/**
	 * @return Locale[]
	 */
	public static function getAll(): array
	{
		if(self::$all === null)
		{
			self::$all = [];
			foreach(self::getConfig() as $urlName => $config)
			{
				self::$all[$urlName] = self::get($urlName);
			}
		}
		
		return self::$all;
	}
	
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
