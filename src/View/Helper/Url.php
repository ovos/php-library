<?php
declare(strict_types=1);

namespace Ovos\View\Helper;

use Ovos\View\Helper;
use Ovos\Url as BaseUrl;

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
	 *
	 * @return string|BaseUrl
	 */
	public function url(...$urlComponents)
	{
		if(\count($urlComponents) === 0)
		{
			return $this->_app->getRequest()->getUrl()->getClone();
		}

		return $this->_app->getRouter()->assemble(...$urlComponents);
	}
}