<?php
declare(strict_types=1);

namespace Tests\Application;

use Ovos\Application;
use Ovos\Response\Html;
use Ovos\Test;
use Ovos\Test\Exception\SkipException;
use ReflectionMethod;
use ReflectionProperty;

/**
 * FinishResponse - a response sent before the request ends
 * (Application::finishResponse(), the page cache's stale page)
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class FinishResponse extends Test
{
	/**
	 * RULE: where the client cannot be released early - a CLI, or an HTTP
	 * request without FastCGI (mod_php) - nothing is sent, and the caller is
	 * told so (it renders for the visitor instead)
	 */
	public function withoutFastCgiNothingIsSent(): bool
	{
		$app = $this->app();
		$interface = $app->getInterface();
		$response = $app->getResponse();
		
		try
		{
			$app->setInterface(Application::INT_CLI);
			$cli = new Html('');
			$fromCli = $app->finishResponse($cli);
			
			$app->setInterface(Application::INT_HTTP);
			$http = new Html('');
			$fromHttp = $app->finishResponse($http);
			
			return $fromCli === false
				&& $cli->isSent() === false
				&& $fromHttp === false
				&& $http->isSent() === false;
		}
		finally
		{
			$app->setInterface($interface);
			$app->setResponse($response);
		}
	}
	
	/**
	 * RULE: after a response went out early, nothing else is sent - the
	 * response the request ends with (the render, an error page) stays
	 * unsent: the client is gone, and its headers went with the first one
	 */
	public function afterAnEarlyResponseNothingElseIsSent(): bool
	{
		$app = $this->app();
		$response = $app->getResponse();
		$early = new ReflectionProperty(Application::class, 'responseSentEarly');
		$send = new ReflectionMethod(Application::class, 'sendResponse');
		
		try
		{
			$early->setValue($app, true);
			$last = new Html('');
			$send->invoke($app, $last);
			
			return $last->isSent() === false;
		}
		finally
		{
			$early->setValue($app, false);
			$app->setResponse($response);
		}
	}
	
	protected function app(): Application
	{
		if(Application::$instance === null)
		{
			throw new SkipException('no application');
		}
		
		return Application::$instance;
	}
}
