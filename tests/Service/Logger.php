<?php
declare(strict_types=1);

namespace Tests\Service;

use Ovos\Service\Logger as Subject;
use Ovos\Test;

/**
 * Logger — request-variable scrubbing: secret fields are dropped, username
 * fields and e-mail addresses (anywhere) are anonymized. remove() is the
 * single chokepoint for both the file writer and the console Sender.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Logger extends Test
{
	public function redactsTheCanonicalSecretSet(): bool
	{
		$out = (new Subject)->remove([
			'password'      => 'x',
			'access_token'  => 'x',
			'secret'        => 'x',
			'authorization' => 'x',
			'cookie'        => 'x',
			'api_key'       => 'x',
			'api-key'       => 'x',
			'apiKey'        => 'x',
		]);
		
		foreach($out as $value)
		{
			if($value !== '[redacted]')
			{
				return false;
			}
		}
		
		return true;
	}
	
	public function redactionIsCaseInsensitiveAndMatchesSubstrings(): bool
	{
		$out = (new Subject)->remove([
			'PASSWORD'      => 'x',
			'passwd'        => 'x',
			'user_password' => 'x',
			'Authorization' => 'x',
		]);
		
		return $out['PASSWORD'] === '[redacted]'
			&& $out['passwd'] === '[redacted]'
			&& $out['user_password'] === '[redacted]'
			&& $out['Authorization'] === '[redacted]';
	}
	
	public function anonymizesUsernameFieldsKeepingFirstCharacter(): bool
	{
		$out = (new Subject)->remove([
			'username'   => 'marcin',
			'user'       => 'bob',
			'login'      => 'alice',
			'user_name'  => 'carol',
			'user_login' => 'erin',
		]);
		
		return $out['username'] === 'm***'
			&& $out['user'] === 'b***'
			&& $out['login'] === 'a***'
			&& $out['user_name'] === 'c***'
			&& $out['user_login'] === 'e***';
	}
	
	/**
	 * Anchored patterns: identifier fields must survive so errors stay
	 * debuggable (userId is also sent as indexed context).
	 */
	public function leavesUserIdentifierFieldsIntact(): bool
	{
		$out = (new Subject)->remove([
			'userId'    => '1234',
			'userAgent' => 'Mozilla/5.0',
			'userType'  => 'admin',
		]);
		
		return $out['userId'] === '1234'
			&& $out['userAgent'] === 'Mozilla/5.0'
			&& $out['userType'] === 'admin';
	}
	
	public function masksEmailsKeepingTheDomain(): bool
	{
		$out = (new Subject)->remove([
			'email'   => 'john.doe@example.com',
			'contact' => 'support@gmail.com', // e-mail in a non-e-mail field
		]);
		
		return $out['email'] === 'j***@example.com'
			&& $out['contact'] === 's***@gmail.com';
	}
	
	public function masksEveryEmailInFreeText(): bool
	{
		$out = (new Subject)->remove([
			'note' => 'ping bob@x.co and jane+tag@sub.example.org please',
		]);
		
		return $out['note'] === 'ping b***@x.co and j***@sub.example.org please';
	}
	
	/**
	 * A username that is an e-mail is masked as an e-mail (domain kept), not
	 * chopped to first-char — the value pattern wins over the field name.
	 */
	public function usernameThatIsAnEmailIsMaskedAsEmail(): bool
	{
		$out = (new Subject)->remove(['username' => 'john@company.com']);
		
		return $out['username'] === 'j***@company.com';
	}
	
	public function secretsAreRemovedEvenWhenValuedLikeAnEmail(): bool
	{
		$out = (new Subject)->remove(['password' => 'admin@corp.com']);
		
		return $out['password'] === '[redacted]';
	}
	
	public function recursesIntoNestedArraysIncludingListValues(): bool
	{
		$out = (new Subject)->remove([
			'nested' => [
				'password' => 'deep',
				'email'    => 'deep@nest.io',
				'username' => 'deepuser',
				'list'     => ['a@b.co', 'plain', 42],
			],
		]);
		
		return $out['nested']['password'] === '[redacted]'
			&& $out['nested']['email'] === 'd***@nest.io'
			&& $out['nested']['username'] === 'd***'
			&& $out['nested']['list'][0] === 'a***@b.co' // e-mail reaches numeric keys
			&& $out['nested']['list'][1] === 'plain'
			&& $out['nested']['list'][2] === 42;          // non-string untouched
	}
	
	public function addRemoveExtendsThePatterns(): bool
	{
		$logger = (new Subject)->addRemove(['~^custom_field$~i']);
		
		$out = $logger->remove([
			'custom_field' => 'sensitive',
			'password'     => 'x', // defaults still apply
		]);
		
		return $out['custom_field'] === '[redacted]'
			&& $out['password'] === '[redacted]';
	}
	
	public function addUsernamesExtendsThePatterns(): bool
	{
		$logger = (new Subject)->addUsernames(['~^nick$~i']);
		
		$out = $logger->remove([
			'nick'     => 'marcin',
			'username' => 'bob', // defaults still apply
		]);
		
		return $out['nick'] === 'm***'
			&& $out['username'] === 'b***';
	}
	
	/**
	 * pwd has no "pass" substring, so pass(word|wd)? misses it — it needs its
	 * own pattern (it is also wp-login's field name).
	 */
	/**
	 * Single-use credentials travel in PATHS and are followed over GET, where a
	 * query-parameter rule never sees them. The cases mirror ovos/console's
	 * shared corpus (project/application/tests/fixtures/looks-secret.json) —
	 * this library cannot read that file, so keep the two in step: the console's
	 * browser and node clients and its server-side backstop all answer the same.
	 */
	public function redactsTokenShapedPathSegments(): bool
	{
		$logger = new Subject;
		
		return $logger->removeFromUrl('/reset/eyJhbGciOiJIUzI1NiJ9.payloadpayload.sigsig/')
				=== '/reset/[redacted]/'
			&& $logger->removeFromUrl('/invite/3f2504e0-4f89-11d3-9a0c-0305e82c3301')
				=== '/invite/[redacted]'
			&& $logger->removeFromUrl('/x/a1b2c3d4e5f6a7b8c9d0e1f2') === '/x/[redacted]'
			&& $logger->removeFromUrl('/x/Xk7Qm2Rt9Zp4Lw8Nv3Bc6Hj1Fd5Gy0As') === '/x/[redacted]';
	}
	
	/**
	 * The other half, and the reason the rule is not length-only: a readable
	 * slug is not a secret, and a version that redacted every 24+ character
	 * segment turned German page paths into /de/[redacted]/ wherever it shipped.
	 */
	public function leavesReadableSlugsIntact(): bool
	{
		$logger = new Subject;
		
		foreach(['/de/pre-und-onboarding/', '/de/anmeldung-und-registrierung/',
			'/produkte/loesungen-fuer-unternehmen-2024/',
			'/blog/how-we-cut-our-p95-latency-in-half/',
			'/berichte/jahresbericht-2025-final'] as $url)
		{
			if($logger->removeFromUrl($url) !== $url)
			{
				return false;
			}
		}
		
		return true;
	}
	
	/**
	 * The same rule read a content hash as a token, which is what every build
	 * tool names its output after. A JS error names the file it came from, and
	 * one site stored 273 reports whose file was ".../[redacted]?group=true" —
	 * unable to say which bundle threw. Nothing was protected: the browser had
	 * fetched the file over a plain, uncredentialed request.
	 */
	public function leavesHashedAssetFilenamesIntact(): bool
	{
		$logger = new Subject;
		
		foreach([
			// <group>_<md5>.<mtime>.js — a CMS group bundle
			'/base_bf6051fdf10449dbde8945baf61cdf14.1785056998.js',
			// webpack contenthash, and its source map
			'/static/main.9f8e7d6c5b4a39281706f5e4d3c2b1a0.js.map',
			// vite, which trips the mixed-case clause instead
			'/assets/index-DkL9mQxZ8vB2nR4tY7wA.js',
			'/fonts/fa-solid-900.d0b8e0c6f4a2b1937c5d8e2f.woff2',
		] as $url)
		{
			if($logger->removeFromUrl($url) !== $url)
			{
				return false;
			}
		}
		
		// and the line holds: an extension is not an escape hatch
		return $logger->removeFromUrl('/files/Ab3Cd4Ef5Gh6Ij7Kl8Mn9Op0Qr1St2.pdf')
			=== '/files/[redacted]';
	}
	
	/**
	 * ?key= is where reset tokens actually travel (WordPress: wp-login.php?
	 * action=rp&key=<20 chars>&login=<user>) and api[_-]?key never matched it.
	 * Only as a query NAME — as a field name `key` is a cache key half the time.
	 */
	public function redactsGenericQueryNames(): bool
	{
		$logger = new Subject;
		
		return $logger->removeFromUrl('/wp-login.php?action=rp&key=Qw3rTy8ZxC1vB2nM4kL6&login=x')
				=== '/wp-login.php?action=rp&key=[redacted]&login=x***'
			&& $logger->removeFromUrl('/dl?sig=abc123&file=r.pdf') === '/dl?sig=[redacted]&file=r.pdf'
			&& ($logger->remove(['key' => 'cache-v3'])['key'] ?? null) === 'cache-v3';
	}
	
	public function redactsThePwdAbbreviation(): bool
	{
		$out = (new Subject)->remove([
			'pwd'         => 'hunter2',
			'confirm_pwd' => 'hunter2',
		]);
		
		return $out['pwd'] === '[redacted]'
			&& $out['confirm_pwd'] === '[redacted]';
	}
	
	/**
	 * A secret key is dropped before recursion, so a secret whose value is an
	 * array (password[]=…) cannot leak through its children.
	 */
	public function dropsSecretKeysHoldingArraysWholesale(): bool
	{
		$out = (new Subject)->remove([
			'password' => ['a', 'b'],
			'token'    => ['x' => 'y'],
		]);
		
		return $out['password'] === '[redacted]'
			&& $out['token'] === '[redacted]';
	}
	
	/**
	 * The same data request.get carries also rides in context.uri/referer —
	 * secret params dropped, e-mail values masked (decoded, re-encoded
	 * readably), username params anonymized, untouched params byte-identical.
	 */
	public function scrubsSecretsAndEmailsFromUrls(): bool
	{
		$logger = new Subject;
		
		return $logger->removeFromUrl('/checkout?step=2&token=abc123&email=john%40x.com')
				=== '/checkout?step=2&token=[redacted]&email=j***@x.com'
			&& $logger->removeFromUrl('/account?login=marcin&page=3')
				=== '/account?login=m***&page=3'
			&& $logger->removeFromUrl('/unsubscribe/john@x.com?a=1')
				=== '/unsubscribe/j***@x.com?a=1'
			&& $logger->removeFromUrl('/plain/path') === '/plain/path';
	}
	
	/**
	 * CLI argv: --key=value and bare "name value" pair styles both covered;
	 * e-mails masked in any argument.
	 */
	public function scrubsSecretsAndEmailsFromArgs(): bool
	{
		$out = (new Subject)->removeFromArgs([
			'cli.php',
			'import',
			'--api-key=XYZ',
			'password',
			'hunter2',
			'notify',
			'bob@x.co',
		]);
		
		return $out === [
			'cli.php',
			'import',
			'--api-key=[redacted]',
			'password',
			'[redacted]',
			'notify',
			'b***@x.co',
		];
	}
}
