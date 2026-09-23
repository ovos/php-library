<?php
declare(strict_types=1);

namespace Ovos\Plugins\Console;

use Ovos\ArrayObject;
use Ovos\Controller\Plugin;
use Ovos\Response\Html;
use Ovos\Service\Console\Sender;
use Ovos\Service\Console\Shield as Service;
use Override;
use Throwable;

/**
 * The Shield's controller plugin: the request is judged before the action
 * runs (Ovos\Service\Console\Shield over the console's kernel), a match is
 * reported through the console Sender, and a `block` — a PROVEN rule under
 * `console.shield.enforce` — becomes the application's own 403, the way the
 * Csrf plugin refuses: the response is set and the controller is marked
 * dispatched. Register it in the http plugin list beside `Security\Csrf`:
 *
 *   plugins:
 *     default:
 *       http:
 *         - Security\Csrf
 *         - Console\Shield   # after Csrf, before anything that acts on the request
 *
 * and switch it on per project under `console.shield` (see the Service).
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
			$config = $this->app->getConfig()->getPath(['console']);
			$shield = new Service(
				$config instanceof ArrayObject ? $config : null,
				Sender::cachePrefix($this->app),
				null,
				$this->reporter(),
			);
			if($shield->isEnabled() === false)
			{
				return;
			}
			$verdict = $shield->judge($shield->facts($this->app));
			if($verdict->isBlock() && $verdict->rule !== null)
			{
				$this->app->setResponse(self::response((int)$verdict->rule['id']));
				$this->getController()->setDispatched(true);
			}
		}
		catch(Throwable)
		{
			// fail open: served as if the shield were not there
		}
	}
	
	/** the refusal — plain text, the rule named for the person who reads the response */
	public static function response(
		int $ruleId,
	): Html
	{
		return (new Html("Forbidden\n"))
			->setHttpCode(403)
			->setHeader('Content-Type', 'text/plain; charset=utf-8')
			->setHeader(self::HEADER_RULE, (string)$ruleId);
	}
	
	/** where a match goes: the console Sender's security channel, when the app runs one */
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
			// no console sender in this app: the match is counted, not reported
		}
		
		return null;
	}
}
