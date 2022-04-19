<?php
declare(strict_types=1);

namespace Ovos\View\Helper;

use Ovos\Locale;
use Ovos\Locales as BaseLocales;
use Ovos\View;
use Ovos\View\Helper;

/**
 * Locales
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Locales extends Helper
{
	/**
	 * @return Locale[]
	 */
	public function getAll(): array
	{
		return BaseLocales::getAll();
	}
}
