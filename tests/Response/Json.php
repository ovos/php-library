<?php
declare(strict_types=1);

namespace Tests\Response;

use Ovos\Test;
use Ovos\Response\Json as JsonResponse;

use function json_decode;

/**
 * Json response
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Json extends Test
{
	/**
	 * Regression: app code may set a data field named `errors` to a scalar
	 * (e.g. a deleted-count). hasErrors() must treat `errors` as the validation
	 * channel only when it is really an array — a scalar there previously made
	 * count() throw, and because __toString() runs after send() has already
	 * flushed the 200 headers, that blanked the entire response body.
	 */
	public function scalarErrorsFieldDoesNotThrow(): bool
	{
		$response = new JsonResponse;
		$response->errors = 37;
		
		return $response->hasErrors() === false;
	}
	
	/**
	 * ...and the response still serializes, with every field intact.
	 */
	public function scalarErrorsFieldStillSerializes(): bool
	{
		$response = new JsonResponse;
		$response->deleted = 4;
		$response->errors = 37;
		
		$decoded = json_decode((string)$response, true);
		
		return $decoded['success'] === true
			&& $decoded['deleted'] === 4
			&& $decoded['errors'] === 37;
	}
	
	/**
	 * A real validation error (array, set via error()) is still detected.
	 */
	public function arrayValidationErrorsDetected(): bool
	{
		$response = new JsonResponse;
		$response->error('required', 'name');
		
		return $response->hasErrors() === true;
	}
	
	/**
	 * A non-empty singular `error` string is still detected.
	 */
	public function singularErrorStringDetected(): bool
	{
		$response = new JsonResponse;
		$response->error = 'boom';
		
		return $response->hasErrors() === true;
	}
	
	/**
	 * RULE: a JSON answer goes out with Cache-Control: no-store unless its
	 * controller sets a Cache-Control of its own, which replaces it.
	 * Falsify: drop the header from the constructor — the answer then carries
	 * only mod_expires' max-age=0, which lets the browser's disk cache and a
	 * shared cache in front keep a signed-in user's API answers.
	 */
	public function aJsonAnswerIsNeverStoredUnlessItsControllerSaysSo(): bool
	{
		$default = new JsonResponse;
		$own = (new JsonResponse)
			->setHeader('Cache-Control', 'private, max-age=60');
		
		return ($default->getHeaders()['Cache-Control']['value'] ?? null) === 'no-store'
			&& $own->getHeaders()['Cache-Control']['value'] === 'private, max-age=60';
	}
}
