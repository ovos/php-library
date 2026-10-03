<?php
declare(strict_types=1);

namespace Tests\Plugins\Security;

use Ovos\Plugins\Security\Csrf as Subject;
use Ovos\Test;

use function str_contains;

/**
 * Csrf - the pure first-party verdict (the plugin's dispatch behaviour
 * is exercised by a live request, not the unit runner)
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Csrf extends Test
{
	protected const string OWN = 'https://app.example.com';
	
	public function fetchMetadataFirstPartyPasses(): bool
	{
		return Subject::evaluate('same-origin', null, self::OWN)[0] === true
			&& Subject::evaluate('same-site', null, self::OWN)[0] === true
			// direct navigation: address bar, bookmark
			&& Subject::evaluate('none', null, self::OWN)[0] === true;
	}
	
	public function fetchMetadataCrossSiteIsRejected(): bool
	{
		[$allowed, $reason] = Subject::evaluate(
			'cross-site', 'https://evil.example.net', self::OWN);
			
		return $allowed === false
			&& $reason !== '';
	}
	
	public function crossSiteAllowlistedOriginPasses(): bool
	{
		return Subject::evaluate(
			'cross-site',
			'https://partner.example.org',
			self::OWN,
			['https://partner.example.org'],
		)[0] === true;
	}
	
	/**
	 * trust_same_site: no - a sibling host (any *.example.com) is not
	 * first-party: refused unless allowlisted; same-origin and a direct
	 * navigation still pass
	 */
	public function aSiblingHostIsRefusedWhenSameSiteIsNotTrusted(): bool
	{
		[$allowed, $reason] = Subject::evaluate(
			'same-site', 'https://customer.example.com', self::OWN, [], false);
		
		return $allowed === false
			&& str_contains($reason, 'same-site')
			&& Subject::evaluate('same-site', 'https://www.example.com', self::OWN,
				['https://www.example.com'], false)[0] === true
			&& Subject::evaluate('same-origin', self::OWN, self::OWN, [], false)[0] === true
			&& Subject::evaluate('none', null, self::OWN, [], false)[0] === true
			// the default keeps trusting it, as before
			&& Subject::evaluate('same-site', 'https://customer.example.com', self::OWN)[0] === true;
	}
	
	public function noBrowserMetadataPasses(): bool
	{
		// curl / server-to-server: no ambient cookies, nothing to forge
		return Subject::evaluate(null, null, self::OWN)[0] === true
			&& Subject::evaluate('', '', self::OWN)[0] === true;
	}
	
	public function originFallbackDecidesWithoutFetchMetadata(): bool
	{
		return Subject::evaluate(null, self::OWN, self::OWN)[0] === true
			&& Subject::evaluate(null, 'https://evil.example.net', self::OWN)[0] === false
			&& Subject::evaluate(null, 'https://partner.example.org', self::OWN,
				['https://partner.example.org'])[0] === true;
	}
	
	public function opaqueOriginIsRejected(): bool
	{
		// a sandboxed iframe / data: url sends the literal "null"
		return Subject::evaluate(null, 'null', self::OWN)[0] === false;
	}
	
	public function normalizationIsForgiving(): bool
	{
		// case and a trailing slash must not flip a verdict
		return Subject::evaluate('Same-Origin', null, self::OWN)[0] === true
			&& Subject::evaluate(null, 'HTTPS://APP.EXAMPLE.COM/', self::OWN)[0] === true;
	}
}
