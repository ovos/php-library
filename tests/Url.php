<?php
declare(strict_types=1);

namespace Tests;

use Ovos\Test;
use Ovos\Url as BaseUrl;

/**
 * Url
 *
 * @package Tests
 * @author Marcin Gil <mg@ovos.at>
 */
class Url extends Test
{
	public function firstTest()
	{
		$url = new BaseUrl('test', 'param');
		$urlWithHost = $url->getWithHost();
		
		return true;
	}
	
	public function secondTest()
	{
		return true;
	}
}
