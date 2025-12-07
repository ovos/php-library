<?php
declare(strict_types=1);

namespace Ovos\View\Helper;

use Ovos\Container\Inject;
use Ovos\Service\Auth;
use Ovos\View\Helper;
use Models\User as Model;

/**
 * User
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class User extends Helper
{
	protected Auth $authService;
	
	public function __construct(
		#[Inject(Auth::SYMBOL)] Auth $authService,
	)
	{
		parent::__construct();
		
		$this->authService = $authService;
	}
	
	public function user(): ?Model
	{
		return $this->authService
			?->getUser();
	}
}
