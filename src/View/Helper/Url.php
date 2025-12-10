<?php
declare(strict_types=1);

namespace Ovos\View\Helper;

use Ovos\View\Helper;
use Ovos\Url as BaseUrl;

use function count;

/**
 * User
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Url extends Helper
{
	/**
	 * @param string[] $urlComponents
	 */
	public function url(
		...$urlComponents,
	): BaseUrl|string
	{
		if(count($urlComponents) === 0)
		{
			return $this->app->getRequest()
				->getUrl()
				->getClone();
		}
		
		return $this->app->getRouter()
			->assemble(...$urlComponents);
	}
}
