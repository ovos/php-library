<?php

namespace Ovos;

/**
 * Bootstrap initialization (should be included in a bootstrap)
 *
 * @author Marcin Gil <mg@ovos.at>
 */

/**
 * Set a default timezone if not defined in php config
 */
if(date_default_timezone_get() === '')
{
	date_default_timezone_set('Europe/Vienna');
}

/**
 * Composer autoloader
 */
require_once BASE_DIR . 'vendor/autoload.php';
