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
}
