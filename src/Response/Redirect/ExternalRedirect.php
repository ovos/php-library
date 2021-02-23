<?php
declare(strict_types=1);

namespace Ovos\Response\Redirect;

use Ovos\Response\Redirect;
use Ovos\Response;
use Ovos\Url;

/**
 * ExternalRedirect
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class ExternalRedirect extends Redirect
{
	/**
	 * @var string
	 */
	protected string $_externalUrl;

	/**
	 * @param string $externalUrl
	 */
	public function __construct(string $externalUrl)
	{
		Response::__construct();
	
		$this->_externalUrl = $externalUrl;
	}

	/**
	 * @return string
	 */
	public function __toString(): string
	{
		return $this->_externalUrl;
	}
}
