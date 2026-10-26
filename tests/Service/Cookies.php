<?php
declare(strict_types=1);

namespace Tests\Service;

use Ovos\Application;
use Ovos\ArrayObject;
use Ovos\Request;
use Ovos\Service\Cookies as CookiesService;
use Ovos\Test;

/**
 * Cookies — the attribute policy every cookie the framework sends goes through
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Cookies extends Test
{
	/**
	 * RULE: an instance configured for https (system.protocol) marks every
	 * cookie Secure, also when the request reaching PHP does not look secure
	 * (TLS ends at a proxy that sends no X-Forwarded-Proto); on an http
	 * instance the flag follows the request, and without it SameSite=None
	 * falls back to Lax.
	 * Falsify: read secure off Request::isSecure() alone — the session cookie
	 * of an https instance behind such a proxy then goes out without Secure,
	 * and SameSite drops to Lax.
	 */
	public function anHttpsInstanceMarksEveryCookieSecure(): bool
	{
		$proxied = $this->options('https', secureRequest: false);
		$direct = $this->options('https', secureRequest: true);
		$plainSecure = $this->options('http', secureRequest: true);
		$plain = $this->options('http', secureRequest: false);
		
		return $proxied['secure'] === true
			&& $proxied['samesite'] === 'None'
			&& $direct['secure'] === true
			&& $plainSecure['secure'] === true
			&& $plain['secure'] === false
			&& $plain['samesite'] === 'Lax';
	}
	
	/**
	 * The options() answer of a Cookies service over a config naming
	 * $protocol, for a request whose isSecure() answers $secureRequest
	 *
	 * @return array<string, mixed>
	 */
	protected function options(
		string $protocol,
		bool $secureRequest,
	): array
	{
		$config = new ArrayObject([
			'cookies' => ['prefix' => null, 'samesite' => 'None'],
			'session' => ['cookie_name' => 'session'],
			'system' => ['protocol' => $protocol],
		]);
		
		$cookies = new class($config, $secureRequest) extends CookiesService
		{
			public function __construct(
				ArrayObject $config,
				bool $secureRequest,
			)
			{
				parent::__construct($config);
				
				// the request decides nothing else here: only isSecure() is asked
				$this->request = new class($secureRequest) extends Request
				{
					public function __construct(
						protected bool $secureStub,
					)
					{
					}
					
					public function isSecure(): bool
					{
						return $this->secureStub;
					}
				};
				// the running application, for its domain
				$this->app = Application::$instance;
			}
		};
		
		return $cookies->options();
	}
}
