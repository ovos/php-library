<?php
declare(strict_types=1);

namespace Tests;

use Ovos\Test;
use Ovos\Url as BaseUrl;

/**
 * Url
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Url extends Test
{
	public function fromArray()
	{
		$url = new BaseUrl('test', 'param');
		return 'test/param/' === $url->getUrl(true);
	}
	
	public function fromStringWithSlash()
	{
		$url = new BaseUrl('test/param/');
		return 'test/param/' === $url->getUrl(true);
	}
		
	public function fromString()
	{
		$url = new BaseUrl('test/param');
		return 'test/param/' === $url->getUrl(true);
	}
	
	public function add()
	{
		$url = new BaseUrl('test');
		$url->add('param' , 'two');
		
		return 'test/param/two/' === $url->getUrl(true);
	}
	
	public function setLast()
	{
		$url = new BaseUrl('test', 'param');
		$url->setLast('different');
		
		return 'test/different/' === $url->getUrl(true);
	}
}
