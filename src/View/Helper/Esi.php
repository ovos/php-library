<?php
declare(strict_types=1);

namespace Ovos\View\Helper;

use Closure;
use Ovos\Strings;
use Ovos\View;
use Ovos\View\Helper;

use function str_contains;

/**
 * Esi — surrogate-negotiated edge-side includes.
 *
 * One call site, two behaviors. Behind an ESI-capable surrogate (announced
 * by a `Surrogate-Capability` request header carrying `ESI/1.0`), the helper
 * emits an <esi:include> tag and stamps the response with
 * `Surrogate-Control: content="ESI/1.0"` so the surrogate turns its parser
 * on for exactly this response. Without a surrogate — development, or the
 * cache dropping out of the path in production — the fragment renders
 * inline instead: same markup contract, no environment checks anywhere.
 *
 * The fragment endpoint at $src should render the same view script as the
 * inline fallback, so both paths produce identical HTML; its response
 * carries its own Cache-Control, which is the whole point — the surrogate
 * caches the parent page and the fragment on independent policies.
 *
 * Varnish is the reference surrogate; the two VCL hooks this helper
 * negotiates with:
 *
 *   sub vcl_recv {
 *       set req.http.Surrogate-Capability = "key=ESI/1.0";
 *   }
 *   sub vcl_backend_response {
 *       if (beresp.http.Surrogate-Control ~ "ESI/1.0") {
 *           unset beresp.http.Surrogate-Control;
 *           set beresp.do_esi = true;
 *       }
 *   }
 *
 * Sibling feature: View::cache() is the server-side fragment cache — reach
 * for it when the fragment is shareable and no surrogate is in play; reach
 * for ESI when a cached page needs a sliver with a different cache policy.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Esi extends Helper
{
	public const string CAPABILITY_TOKEN = 'ESI/1.0';
	
	/**
	 * Emit an <esi:include> for the surrogate, or render the fallback inline
	 *
	 * @param string $src fragment URL the surrogate fetches as a subrequest
	 * @param Closure|string $fallback view script path rendered inline when
	 *     no surrogate fronts the request, or a closure returning the markup
	 * @param array $variables variables for a view-script fallback
	 * @param bool $ignoreErrors emit onerror="continue" so a failed include
	 *     does not abort the parent response mid-stream (honored by Varnish
	 *     >= 6.4 with the esi_include_onerror feature, ignored by older
	 *     versions — where a failing fragment can still truncate the page)
	 */
	public function esi(
		string $src,
		Closure|string $fallback,
		array $variables = [],
		bool $ignoreErrors = true,
	): string
	{
		if($this->hasSurrogate() === false)
		{
			return $fallback instanceof Closure
				? (string)$fallback()
				: (new View)->render($fallback, $variables);
		}
		
		$this->app->getResponse()
			->setHeader(
				'Surrogate-Control',
				'content="' . self::CAPABILITY_TOKEN . '"',
				true,
			);
		
		return '<esi:include src="' . Strings::escapeForHtml($src) . '"'
			. ($ignoreErrors ? ' onerror="continue"' : '')
			. '/>';
	}
	
	/**
	 * An ESI/1.0-capable surrogate fronts this request
	 */
	public function hasSurrogate(): bool
	{
		$capability = $this->app->getRequest()
			->getServer('HTTP_SURROGATE_CAPABILITY');
		
		return $capability !== null
			&& str_contains($capability, self::CAPABILITY_TOKEN);
	}
}
