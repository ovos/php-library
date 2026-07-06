<?php
declare(strict_types=1);

namespace Ovos\Route;

use Ovos\Url;

/**
 * Resolution
 *
 * A resolver's answer: which controller/action to dispatch and with
 * what params - or, for a canonical redirect, the target Url (the
 * Router hands it to Application, which sends a 301 before dispatch).
 *
 * @author Marcin Gil <mg@ovos.at>
 */
final class Resolution
{
	public function __construct(
		public readonly string $controllerClass, // relative to Controllers\
		public readonly string $action,
		public readonly array $params = [], // positional args for the action
		public readonly ?Url $redirect = null, // canonical 301 target
	)
	{
	}
	
	/**
	 * A redirect-only resolution - no dispatch happens, Application sends
	 * the 301 to the canonical url
	 */
	public static function redirect(
		Url $url,
	): self
	{
		return new self('', '', [], $url);
	}
	
	public function isRedirect(): bool
	{
		return $this->redirect !== null;
	}
}
