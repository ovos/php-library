<?php
declare(strict_types=1);

namespace Ovos\Response;

/**
 * Xml
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Xml extends Html
{
	/**
	 * Construct
	 *
	 * @param string $response
	 */
	public function __construct(string $response = null)
	{
		parent::__construct($response);

		$this->setHeader('Content-Type', 'text/xml; charset=utf-8');
	}
}
