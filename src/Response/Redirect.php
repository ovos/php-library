<?php
declare(strict_types=1);

namespace Ovos\Response;

use Ovos\Response;
use Ovos\Url;

/**
 * Redirect
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Redirect extends Response
{
	/**
	 * @var Url
	 */
	protected Url $_url;

	/**
	 * HTTP code
	 *
	 * @var int
	 */
	protected int $_httpCode = 302;

	/**
	 * @param Url|string[] $urlComponents
	 */
	public function __construct(...$urlComponents)
	{
		parent::__construct();

		if(isset($urlComponents[0]) && ($urlComponents[0] instanceof Url))
		{
			$this->_url = $urlComponents[0];
			return;
		}

		if(\count($urlComponents) === 0)
		{
			$urlComponents[] = '/';
		}

		$this->_url = new Url(...$urlComponents);
	}

	/**
	 * @return Url
	 */
	public function getUrl(): Url
	{
		return $this->_url;
	}

	/**
	 * Send headers
	 *
	 * @return $this
	 */
	public function sendHeaders(): Response
	{
		$url = $this->__toString();
		if($url !== null)
		{
			$this->setHeader('Location', $url);
		}

		parent::sendHeaders();

		return $this;
	}

	/**
	 * @return string
	 */
	public function __toString(): string
	{
		return $this->_url->__toString();
	}
}
