<?php
declare(strict_types=1);

namespace Ovos\Response;

use Ovos\Response;
use Ovos\Url;
use Override;

use function count;

/**
 * Redirect
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Redirect extends Response
{
	protected Url $url;
	
	/**
	 * HTTP code
	 */
	protected int $httpCode = 302;
	
	protected bool $withHost = false;
	
	protected bool $withQueryString = false;
	
	/**
	 * @param Url|string[] $urlComponents
	 */
	public function __construct(
		...$urlComponents,
	)
	{
		parent::__construct();
		
		if(isset($urlComponents[0])
			&& ($urlComponents[0] instanceof Url))
		{
			$this->url = $urlComponents[0];
			return;
		}
		
		if(count($urlComponents) === 0)
		{
			$urlComponents[] = '/';
		}
		
		$this->url = new Url(...$urlComponents);
	}
	
	public function getUrl(): Url
	{
		return $this->url;
	}
	
	/**
	 * Send headers
	 */
	public function sendHeaders(): static
	{
		$url = $this->__toString();
		$this->setHeader('Location', $url);
		
		parent::sendHeaders();
		
		return $this;
	}
	
	public function withHost(
		bool $withHost = true,
	): static
	{
		$this->withHost = $withHost;
		
		return $this;
	}
	
	public function withQueryString(
		bool $withQueryString = true,
	): static
	{
		$this->withQueryString = $withQueryString;
		
		return $this;
	}
	
	#[Override]
	public function __toString(): string
	{
		$url = $this->url->__toString();
		
		if($this->withHost)
		{
			$url = SYSTEM_HOST . $url;
		}
		
		if($this->withQueryString && $_SERVER['QUERY_STRING'] !== '')
		{
			$url.= '?' . $_SERVER['QUERY_STRING']; 
		}
		
		return $url;
	}
}
