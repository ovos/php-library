<?php

namespace Ovos;

/**
 * Bootstrap file for CLI application
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */

// define base dir
define('BASE_DIR', __DIR__ . DIRECTORY_SEPARATOR);
// define custom configs dir
define('CONFIGS_DIR', BASE_DIR);

// include bootstrap initialization
require_once BASE_DIR . 'init.php';

// run the application
$app = new Application(Application::INT_CLI);
$app->run();
