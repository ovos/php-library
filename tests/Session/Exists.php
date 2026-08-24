<?php
declare(strict_types=1);

namespace Tests\Session;

use Ovos\Arrays;
use Ovos\Service\Session as SessionService;
use Ovos\Test;

use function Ovos\container;

/**
 * Session::exists() — the guard for auth-style reads: it answers "does this
 * request have a session" without the asking creating one. The data
 * accessors start the session as a side effect by design (that is what
 * makes the lazy store convenient), which is exactly why a bare "is someone
 * logged in?" get() hands every anonymous visitor — and every scanner
 * probe — a session, a Set-Cookie and a storage entry. This predicate is
 * what an auth restore checks first instead.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Exists extends Test
{
	/**
	 * On a request that carries no session, exists() answers false — and
	 * the ASKING changes nothing: still no id, still nothing started. Once
	 * a session starts (the CLI's local store here), it answers true.
	 * Writes need no guard, which is the other half of the contract:
	 * needing to store something is the legitimate reason to start.
	 */
	public function existsNeverStartsWhatItAsksAbout(): bool
	{
		$session = new SessionService(Arrays::deepToArrayObject([
			'cookies' => ['domain' => ''],
			'session' => ['handler' => SessionService::HANDLER_PHP],
		]));
		container()->injectObject($session);
		
		$before = $session->exists();
		$idBefore = $session->getId();
		
		$session->start();
		
		return $before === false
			&& $idBefore === null
			&& $session->exists() === true;
	}
}
