<?php
declare(strict_types=1);

namespace Ovos\Controller;

use Ovos\Controller;
use Ovos\Exception;
use Ovos\Http\Body;
use Ovos\Http\Input;
use Ovos\Response\Json;
use Throwable;

/**
 * Api
 *
 * Base controller for JSON API endpoints: request-body parsing plus the common
 * Response\Json envelopes (400/404/422/500/503), so actions stop repeating the
 * plumbing. Success is the default — a fresh Response\Json is already a success,
 * so list/ok responses need no helper.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Api extends Controller
{
	public const int BODY_MAX_BYTES = 1048576;
	
	/**
	 * The decoded JSON request body as an array, or null when missing, too large
	 * or malformed — the caller answers a null with badRequest().
	 */
	protected function jsonBody(
		int $maxBytes = self::BODY_MAX_BYTES,
	): ?array
	{
		return Body::json($maxBytes);
	}
	
	/**
	 * The request body as a typed Input reader (string/int/bool/array/
	 * ids/email…), empty when the body is missing or malformed - the
	 * recommended way to read a JSON payload without hand-rolled casts.
	 */
	protected function input(
		int $maxBytes = self::BODY_MAX_BYTES,
	): Input
	{
		return Body::input($maxBytes);
	}
	
	/**
	 * A capped list of positive int ids from a single {id} or an {ids:[…]} body.
	 */
	protected function bodyIds(
		array $body,
		int $max = 100,
	): array
	{
		return Body::ids($body, $max);
	}
	
	/**
	 * 400 — a malformed request; silent, since a client mistake is not worth logging.
	 */
	protected function badRequest(
		string $message = 'invalid request',
	): Json
	{
		return (new Json)
			->failure($message, true)
			->setHttpCode(400);
	}
	
	/**
	 * 404 — the addressed resource does not exist.
	 */
	protected function notFound(
		string $message = 'not found',
	): Json
	{
		return (new Json)
			->failure($message, true)
			->setHttpCode(404);
	}
	
	/**
	 * 422 — the payload was understood but failed validation; $errors is a
	 * field => message map.
	 */
	protected function unprocessable(
		array $errors,
	): Json
	{
		return (new Json)
			->errors($errors)
			->setHttpCode(422);
	}
	
	/**
	 * 503 — a dependency is unavailable (e.g. the search backend). The client
	 * gets the generic $message (never the raw exception, which may leak
	 * internals); pass the caught $cause to log it server-side (file + console)
	 * for debugging. Silent when $cause is null.
	 */
	protected function unavailable(
		string $message = 'service unavailable',
		?Throwable $cause = null,
	): Json
	{
		if($cause !== null)
		{
			$this->logEvent($cause);
		}
		
		return (new Json)
			->failure($message, true)
			->setHttpCode(503);
	}
	
	/**
	 * 500 — an unexpected error; logged (not silent) so it reaches the event log.
	 */
	/**
	 * 500 — the same contract as unavailable() above: the client gets the
	 * generic $error, the caught $cause is logged server-side. Without the
	 * second parameter callers had nowhere to put the exception, so they
	 * concatenated its message into the response instead — which is how an
	 * SMTP host, a DSN or a file path ends up in a JSON body.
	 */
	protected function serverError(
		string|Exception $error = 'internal error',
		?Throwable $cause = null,
	): Json
	{
		if($cause !== null)
		{
			$this->logEvent($cause);
		}
		
		return (new Json)
			->failure($error)
			->setHttpCode(500);
	}
}
