<?php
declare(strict_types=1);

namespace Ovos\Plugins\Security;

use Ovos\ArrayObject;
use Ovos\Console;
use Ovos\Controller\Plugin;
use Ovos\Request;
use Ovos\Response\Html;
use Ovos\Service\Console\Sender;
use Ovos\Service\Logger;
use Override;
use Throwable;

use function in_array;
use function rtrim;
use function strpos;
use function strtolower;
use function substr;
use function trim;

/**
 * Csrf
 *
 * Browser-first CSRF protection - no tokens, no form or client changes.
 * State-changing requests (anything but GET/HEAD/OPTIONS) must originate
 * from the site itself:
 *
 * 1. `Sec-Fetch-Site` (Fetch Metadata, sent by every evergreen browser):
 *    same-origin / same-site / none (direct navigation) pass; cross-site
 *    is rejected unless the Origin is allowlisted (embedding partners).
 * 2. Without Fetch Metadata, the `Origin` header decides against the
 *    request's own origin and the allowlist.
 * 3. No metadata at all (curl, server-to-server) passes - a client that
 *    is not a browser carries no ambient cookies, so there is nothing to
 *    forge. This also means SameSite=None cookie setups (iframe embeds)
 *    finally get real protection.
 *
 * The QUERY method is deliberately NOT exempt - its draft "safe"
 * semantics cannot be assumed of handlers.
 *
 * Register early in the http plugin list and configure:
 *
 *   security:
 *     csrf:
 *       mode: report        # report = log only; enforce = 403
 *       allow_origins:      # cross-site origins allowed to POST
 *         - https://partner.example.com
 *
 * Start in REPORT mode: violations are logged (Logger + dev console)
 * but nothing is blocked - collect legitimate cross-site origins from
 * real traffic, fill allow_origins, then flip to enforce. Exempt
 * key-authenticated API controllers via the plugin groups skip
 * machinery, like any other plugin.
 *
 * High-value actions (login, password change) can still layer a
 * synchronizer token on top - with the page cache, that token is a
 * View::hole().
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Csrf extends Plugin
{
	public const string SYMBOL = 'csrf';
	
	public const string MODE_REPORT = 'report';
	public const string MODE_ENFORCE = 'enforce';
	
	/**
	 * Methods that must not change state - never challenged
	 */
	protected const array SAFE_METHODS = [
		Request::METHOD_GET,
		Request::METHOD_HEAD,
		Request::METHOD_OPTIONS,
	];
	
	/**
	 * Sec-Fetch-Site values that identify a first-party request ('none'
	 * is a direct navigation: address bar, bookmark)
	 */
	protected const array TRUSTED_SITES = [
		'same-origin',
		'same-site',
		'none',
	];
	
	#[Override]
	public function preDispatch(): void
	{
		if($this->request->isCli() === true
			|| in_array($this->request->getMethod(), self::SAFE_METHODS, true) === true)
		{
			return;
		}
		
		$config = $this->app->getConfig()
			->getPath(['security', 'csrf']);
		if($config instanceof ArrayObject === false
			|| $config->get('enabled') === false)
		{
			return; // unconfigured or disabled - inert
		}
		
		[$allowed, $reason] = self::evaluate(
			$this->request->getServer('HTTP_SEC_FETCH_SITE'),
			$this->request->getServer('HTTP_ORIGIN'),
			$this->ownOrigin(),
			$this->allowedOrigins($config),
		);
		
		if($allowed === true)
		{
			return;
		}
		
		$mode = $config->get('mode') ?? self::MODE_REPORT;
		
		$this->report($reason, $mode);
		
		if($mode === self::MODE_ENFORCE)
		{
			$this->app->setResponse(
				(new Html('Forbidden'))->setHttpCode(403));
			$this->getController()->setDispatched(true);
		}
	}
	
	/**
	 * The pure verdict: is this state-changing request first-party (or
	 * explicitly allowlisted)?
	 *
	 * @param string[] $allowOrigins normalized lowercase origins
	 * @return array{0: bool, 1: string} [allowed, reason]
	 */
	public static function evaluate(
		?string $secFetchSite,
		?string $origin,
		string $ownOrigin,
		array $allowOrigins = [],
	): array
	{
		$origin = $origin === null
			? null
			: strtolower(rtrim(trim($origin), '/'));
			
		$secFetchSite = strtolower(trim((string)$secFetchSite));
		if($secFetchSite !== '')
		{
			if(in_array($secFetchSite, self::TRUSTED_SITES, true) === true)
			{
				return [true, 'sec-fetch-site: ' . $secFetchSite];
			}
			
			if($origin !== null
				&& in_array($origin, $allowOrigins, true) === true)
			{
				return [true, 'allowlisted origin: ' . $origin];
			}
			
			return [false, 'cross-site request (sec-fetch-site: '
				. $secFetchSite . ', origin: ' . ($origin ?? '-') . ')'];
		}
		
		// no fetch metadata - an old browser, or not a browser at all
		if($origin === null || $origin === '')
		{
			// no ambient-cookie carrier, nothing to forge
			return [true, 'no browser metadata'];
		}
		
		if($origin === $ownOrigin)
		{
			return [true, 'origin matches'];
		}
		
		if(in_array($origin, $allowOrigins, true) === true)
		{
			return [true, 'allowlisted origin: ' . $origin];
		}
		
		// 'null' = sandboxed iframe / data: url - opaque, never first-party
		return [false, 'foreign origin: ' . $origin];
	}
	
	/**
	 * The request's own scheme://host, lowercased
	 */
	protected function ownOrigin(): string
	{
		return ($this->request->isSecure() === true ? 'https://' : 'http://')
			. strtolower((string)$this->request->getServer('HTTP_HOST'));
	}
	
	/**
	 * @return string[] normalized lowercase origins
	 */
	protected function allowedOrigins(
		ArrayObject $config,
	): array
	{
		$origins = $config->get('allow_origins');
		if($origins instanceof ArrayObject)
		{
			$origins = $origins->getArrayCopy();
		}
		
		$normalized = [];
		foreach((array)($origins ?? []) as $origin)
		{
			$normalized[] = strtolower(rtrim(trim((string)$origin), '/'));
		}
		
		return $normalized;
	}
	
	/**
	 * A violation: one loud log line (the collection mechanism in report
	 * mode) plus a dev-console note — and, when the app runs the console
	 * sender, a type=security event (kind csrf_reject) so the violation
	 * reaches the error console's security channel. Report mode sends too:
	 * that mode exists to collect real cross-site origins, and the channel
	 * is where they are collected; the mode travels in the message.
	 */
	protected function report(
		string $reason,
		string $mode,
	): void
	{
		$line = 'CSRF ' . $mode . ': ' . $this->request->getMethod()
			. ' ' . (string)$this->request->getServer('REQUEST_URI')
			. ' - ' . $reason;
			
		try
		{
			$this->container->get(Logger::SYMBOL)
				->log($line);
		}
		catch(Throwable)
		{
			// no logger in this context
		}
		
		try
		{
			$this->container->getClass(Console::class)
				->setMessage($line);
		}
		catch(Throwable)
		{
			// no console in this context
		}
		
		try
		{
			// the query is dropped: it can carry tokens, and distinct targets
			// should stay distinct while one hammered path folds together
			$uri = (string)$this->request->getServer('REQUEST_URI');
			$mark = strpos($uri, '?');
			
			$sender = $this->container->get(Sender::SYMBOL);
			if($sender instanceof Sender)
			{
				$sender->reportRefusal('csrf_reject',
					'CSRF ' . $mode . ': ' . $this->request->getMethod() . ' '
					. ($mark === false ? $uri : substr($uri, 0, $mark))
					. ' - ' . $reason);
			}
		}
		catch(Throwable)
		{
			// no console sender in this app
		}
	}
}
