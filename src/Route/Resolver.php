<?php
declare(strict_types=1);

namespace Ovos\Route;

use Ovos\Request;
use Ovos\Url;

/**
 * Resolver
 *
 * One link in the routing chain: given the request URL, either claim it
 * (return a Resolution) or decline (return null) so the next resolver -
 * and ultimately the convention Router - can try. Resolvers run BEFORE
 * the convention routing; the first Resolution wins.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
interface Resolver
{
	public function resolve(
		Url $url,
		Request $request,
	): ?Resolution;
}
