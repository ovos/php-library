<?php
declare(strict_types=1);

namespace Ovos\Response;

use Ovos\Response;
use Ovos\Exception;
use function Ovos\app;
use function Ovos\services;

/**
 * Cli
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Cli extends Html
{
	/**
	 * Construct
	 *
	 * @param string $response
	 */
	public function __construct(string $response = null)
	{
		parent::__construct();

		$this->set($response);
	}
}
