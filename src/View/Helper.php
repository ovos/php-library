<?php
declare(strict_types=1);

namespace Ovos\View;

use Ovos\Application;
use Ovos\View;
use function Ovos\app;

/**
 * Helper
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Helper
{
	/**
	 * @var Application
	 */
	protected Application $_app;

	/**
	 */
	public function __construct()
	{
		$this->_app = app();
	}
}
