<?php
declare(strict_types=1);

namespace Ovos\Translator;

use PhpMyAdmin\MoTranslator\Translator; // https://github.com/phpmyadmin/motranslator/issues/30
use Ovos\ArrayObject;
use Ovos\Strings;
use function Ovos\services;

/**
 * CachedTranslator
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class CachedAdapter extends Translator
{
	/**
	 * @param string $filename
	 */
	public function __construct(string $filename)
	{
		if(file_exists($filename) === false)
		{
			return;
		}

		$mTime = filemtime($filename);
		$path = substr($filename, \strlen(BASE_DIR));
		$cacheId = Strings::slugify($path);

		if($pool = services()->cache->getPool())
		{
			if($pool->hasItem($cacheId))
			{
				$cache = $pool->getItem($cacheId)->get();
				if($cache->mtime === $mTime)
				{
					$this->setCachedTranslations($cache->translations);

					return;
				}
			}
		}

		parent::__construct($filename);

		if($pool = services()->cache->getPool())
		{
			$cache = new ArrayObject;
			$cache->mtime = $mTime;
			$cache->translations = $this->getCachedTranslations();
			$item = $pool->getItem($cacheId)->set($cache);
			$pool->save($item);
		}
	}
}
