<?php
declare(strict_types=1);

namespace Ovos\Response;

use Ovos\Response;
use Ovos\Url;

use function count;

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
	 * @var bool
	 */
	protected bool $_withHost = false;

	/**
	 * @var bool
	 */
	protected bool $_withQueryString = false;

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

		if(count($urlComponents) === 0)
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
	 * @return self
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
	 * @param bool $withHost
	 *
	 * @return self
	 */
	public function withHost(bool $withHost = true): self
	{
		$this->_withHost = $withHost;
		
		return $this;
	}

	/**
	 * @param bool $withQueryString
	 *
	 * @return self
	 */
	public function withQueryString(bool $withQueryString = true): self
	{
		$this->_withQueryString = $withQueryString;
		
		return $this;
	}

	/**
	 * @return string
	 */
	public function __toString(): string
	{
		$url = $this->_url->__toString();
		
		if($this->_withHost)
		{
			$url = SYSTEM_HOST . $url;
		}
		
		if($this->_withQueryString && $_SERVER['QUERY_STRING'] !== '')
		{
			$url.= '?' . $_SERVER['QUERY_STRING']; 
		}
		
		return $url;
	}
}
