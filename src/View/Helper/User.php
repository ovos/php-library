<?php
declare(strict_types=1);

namespace Ovos\View\Helper;

use Ovos\View\Helper;
use Models\User as Model;
use function Ovos\services;

/**
 * User
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class User extends Helper
{
	/**
	 * @return ?Model
	 */
	public function user(): ?Model
	{
		return services()->auth->getUser();
	}
}
