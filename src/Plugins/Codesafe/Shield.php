<?php
declare(strict_types=1);

namespace Ovos\Plugins\Codesafe;

use Ovos\Codesafe\Shield\Facts;
use Ovos\Controller\Plugin;
use Ovos\Response\Html;
use Ovos\Service\Codesafe\Sender;
use Ovos\Service\Codesafe\Shield as Service;
use Override;
use Throwable;

use function max;

/**
 * The Shield's controller plugin: the request is judged before the action
 * runs (Ovos\Service\Codesafe\Shield over codesafe's kernel), a match is
 * reported through the codesafe Sender, and a `block` — a PROVEN rule under
 * `codesafe.shield.enforce` — becomes the application's own 403, the way the
 * Csrf plugin refuses: the response is set and the controller is marked
 * dispatched. Register it in the http plugin list beside `Security\Csrf`:
 *
 *   plugins:
 *     default:
 *       http:
 *         - Security\Csrf
 *         - Codesafe\Shield   # after Csrf, before anything that acts on the request
 *
 * It judges as soon as it is registered: `codesafe.shield` detect and enforce
 * are on unless set to no (see the Service). Who is signed in is read when
 * it runs (authed()): after the plugin that sets the app's user.
 * Inert on the CLI and whenever the Service says it is not enabled; fails
 * open on anything thrown — the shield may never be why a page does not
 * load.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Shield extends Plugin
{
	public const string SYMBOL = 'shield';
	
	public const string HEADER_RULE = 'X-Shield-Rule';
	
	#[Override]
	public function preDispatch(): void
	{
		if($this->request->isCli() === true)
		{
			return;
		}
		try
		{
			$shield = new Service(
				Sender::configOf($this->app->getConfig()),
				Sender::cachePrefix($this->app),
				null,
				$this->reporter(),
			);
			if($shield->isEnabled() === false)
			{
				return;
			}
			$verdict = $shield->judge($this->facts($shield));
			if($verdict->isBlock() && $verdict->rule !== null)
			{
				$this->app->setResponse(self::response((int)$verdict->rule['id'], $verdict->status(), $verdict->retryAfter));
				$this->getController()->setDispatched(true);
			}
		}
		catch(Throwable)
		{
			// fail open: served as if the shield were not there
		}
	}
	
	/**
	 * The refusal — plain text, the rule named for the person who reads the
	 * response: 403 for a match rule, 429 with Retry-After for a rate rule
	 * past its limit (the verdict's status() and retryAfter)
	 */
	public static function response(
		int $ruleId,
		int $status = 403,
		int $retryAfter = 0,
	): Html
	{
		$response = (new Html($status === 429 ? "Too Many Requests\n" : "Forbidden\n"))
			->setHttpCode($status === 429 ? 429 : 403)
			->setHeader('Content-Type', 'text/plain; charset=utf-8')
			->setHeader(self::HEADER_RULE, (string)$ruleId);
		if($status === 429)
		{
			$response->setHeader('Retry-After', (string)max(1, $retryAfter));
		}
		
		return $response;
	}
	
	/** the request as the kernel reads it, signed in as authed() says */
	protected function facts(
		Service $shield,
	): Facts
	{
		return $shield->facts($this->app, $this->authed());
	}
	
	/**
	 * Whether the request is a signed-in one: the app's Ovos\Service\Auth
	 * holding a user (Service::isAuthed()); null when it cannot say, which
	 * reads as not signed in. An app whose sign-in is not that service
	 * overrides it in its own Plugins\Codesafe\Shield, which the plugin list's
	 * `Codesafe\Shield` resolves to first, and says so with
	 * `codesafe.shield.authed: yes`
	 */
	protected function authed(): ?bool
	{
		return Service::isAuthed($this->app);
	}
	
	/** where a match goes: the codesafe Sender's security channel, when the app runs one */
	protected function reporter(): ?callable
	{
		try
		{
			$sender = $this->container->get(Sender::SYMBOL);
			if($sender instanceof Sender)
			{
				return static fn(string $kind, string $message, array $extra): Sender => $sender->reportRefusal($kind, $message, $extra);
			}
		}
		catch(Throwable)
		{
			// no codesafe sender in this app: the match is counted, not reported
		}
		
		return null;
	}
}
