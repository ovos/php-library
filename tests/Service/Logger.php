<?php
declare(strict_types=1);

namespace Tests\Service;

use Ovos\Service\Logger as Subject;
use Ovos\Test;

use function str_contains;

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
	
	/**
	 * removeText() — the raw request BODY the console's replay needs
	 * (docs/SENDER.md §context.request). An array has keys to walk; a JSON or
	 * form body has none, so the same secret NAMES are read out of the
	 * punctuation a body is written in instead.
	 *
	 * Prevents: shipping the credential inside a body whole. Without this the
	 * console stores it and the REPLAY posts it straight back at the site.
	 */
	public function redactsSecretsInsideARawBody(): bool
	{
		$logger = new Subject;
		$json = $logger->removeText('{"sku":"X-1","password":"hunter2","note":"bob@example.com"}');
		$form = $logger->removeText('q=shoes&access_token=abc123&page=2');
		$header = $logger->removeText('Authorization: Bearer abc.def.xyz');
		
		return str_contains($json, '"sku":"X-1"')
			&& str_contains($json, 'hunter2') === false
			&& str_contains($json, '[redacted]')
			// the address is masked, its domain kept
			&& str_contains($json, 'bob@example.com') === false
			&& str_contains($json, '@example.com')
			&& str_contains($form, 'q=shoes') && str_contains($form, 'page=2')
			&& str_contains($form, 'abc123') === false
			// the auth scheme is part of the value, so it collapses to one redaction
			&& str_contains($header, 'abc.def.xyz') === false
			&& $header === 'Authorization: [redacted]'
			// idempotent: the console scrubs again server-side
			&& $logger->removeText($json) === $json
			&& $logger->removeText('') === '';
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
	
	/**
	 * Every fourth character survives and the rest become stars, so the mask is
	 * exactly as long as the value was — "bob" (3) and "marcin" (6) must not log
	 * identically, which every value collapsing to "x***" could not tell apart.
	 */
	public function anonymizesUsernameFieldsKeepingEveryFourthCharacter(): bool
	{
		$out = (new Subject)->remove([
			'username'   => 'marcin',
			'user'       => 'bob',
			'login'      => 'alice',
			'user_name'  => 'carol',
			'user_login' => 'erin',
		]);
		
		return $out['username'] === 'm***i*'
			&& $out['user'] === 'b**'
			&& $out['login'] === 'a***e'
			&& $out['user_name'] === 'c***l'
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
		
		return $out['email'] === 'j***.***@example.com'
			&& $out['contact'] === 's***o**@gmail.com';
	}
	
	/**
	 * keepingIdentities() — the copy the console's request data goes through
	 * (console docs/plans/reveal-everything.md): every secret is dropped as
	 * always, in the walk and in free text, while e-mail addresses and
	 * usernames travel as sent; the logger it was made from still masks
	 */
	public function keepingIdentitiesDropsSecretsAndKeepsPeople(): bool
	{
		$logger = new Subject;
		$data = [
			'email' => 'john.doe@example.com',
			'username' => 'marcin',
			'password' => 'hunter22',
			'opts' => ['api_key' => 'k-1', 'contact' => 'bob@x.co'],
			'note' => 'user=marcin password=hunter22 mail bob@x.co',
		];
		$kept = $logger->keepingIdentities()->remove($data);
		$text = $logger->keepingIdentities()->removeText('user=marcin password=hunter22 mail bob@x.co');
		$masked = $logger->remove($data);
		
		return $kept['email'] === 'john.doe@example.com'
			&& $kept['username'] === 'marcin'
			&& $kept['password'] === '[redacted]'
			&& $kept['opts'] === ['api_key' => '[redacted]', 'contact' => 'bob@x.co']
			&& $text === 'user=marcin password=[redacted] mail bob@x.co'
			// the original logger is untouched
			&& $masked['email'] === 'j***.***@example.com'
			&& $masked['username'] !== 'marcin';
	}
	
	/**
	 * RULE: PHP's `::` separates no pair — the method an error names stays
	 * as it was written, whatever its class is called; a real pair beside it
	 * is still masked or redacted. Mirrors codesafe's Scrubber.
	 *
	 * Prevents: `Controllers\User::unlock()` written as `User::***o***)` and
	 * `Ovos\Password::generate()` as `Password:[redacted])` (bo2go
	 * e628a7b51f2cac37, MG 2026-10-08: "we mask the action name and we
	 * should not do it").
	 */
	public function aScopeOperatorSeparatesNoPair(): bool
	{
		$logger = new Subject;
		$methods = [
			'Too few arguments to function Controllers\User::unlock(), 0 passed',
			'Too few arguments to function Ovos\Password::generate(), 0 passed',
			'Call to undefined method Token::verify()',
			'Login::check() and Username::find()',
		];
		foreach($methods as $message)
		{
			if($logger->removeText($message) !== $message)
			{
				return false;
			}
		}
		
		return $logger->removeText('Login::check() failed for user=marcin') === 'Login::check() failed for user=m***i*'
			&& $logger->removeText('user: marcin') === 'user: m***i*'
			&& $logger->removeText('user:marcin') === 'user:m***i*'
			&& $logger->removeText('Token::verify() password: hunter22') === 'Token::verify() password: [redacted]'
			&& $logger->removeText('password:hunter22') === 'password:[redacted]';
	}
	
	public function masksEveryEmailInFreeText(): bool
	{
		$out = (new Subject)->remove([
			'note' => 'ping bob@x.co and jane+tag@sub.example.org please',
		]);
		
		return $out['note'] === 'ping b**@x.co and j***+***@sub.example.org please';
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
	
	/**
	 * The mask is exactly as long as the local part was (the statement
	 * maskName already makes for usernames) — and every shape survives a
	 * username-named field with its domain intact, including the short
	 * locals whose mask holds no *** run and the one-character local whose
	 * mask IS the raw value.
	 */
	public function theEmailMaskSaysHowLongTheLocalPartWas(): bool
	{
		$logger = new Subject;
		
		$out = $logger->remove([
			'username' => 'stefa@example.at',
			'login' => 'm@example.at',
			'user' => 'j***.***@example.com',
		]);
		
		return $out === [
				'username' => 's***a@example.at',
				'login' => 'm@example.at',
				'user' => 'j***.***@example.com',
			]
			// the login param is untouched, so it stays byte-identical —
			// %40 and all (values are only re-encoded when changed)
			&& $logger->removeFromUrl('/x?user=jo%40b.co&login=m%40example.at')
				=== '/x?user=j*@b.co&login=m%40example.at';
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
			&& $out['nested']['username'] === 'd***u***'
			&& $out['nested']['list'][0] === 'a@b.co' // one-char local: the mask IS the value
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
		
		return $out['nick'] === 'm***i*'
			&& $out['username'] === 'b**';
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
		
		return $logger->removeFromUrl('/wp-login.php?action=rp&key=Qw3rTy8ZxC1vB2nM4kL6&login=root')
				=== '/wp-login.php?action=rp&key=[redacted]&login=r***'
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
				=== '/account?login=m***i*&page=3'
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
			'b**@x.co',
		];
	}
	
	/**
	 * keepingIdentities() reaches the url and argv copies too (codesafe
	 * docs/plans/identity-round-2026-10.md): the uri, the referer and the
	 * CLI arguments the codesafe Sender sends keep their e-mail addresses and
	 * username params — codesafe masks and vaults them on arrival — while
	 * every secret rule still holds: a secret-named or query-only param, a
	 * token-shaped path segment, `--password=x` and the value after a bare
	 * secret name. The logger it was made from masks all of it as before
	 */
	public function keepingIdentitiesKeepsPeopleInUrlsAndArgs(): bool
	{
		$logger = new Subject;
		$kept = $logger->keepingIdentities();
		$url = '/unsubscribe/john@x.com/reset/eyJhbGciOiJIUzI1NiJ9.payloadpayload.sigsig'
			. '?email=anna%40example.at&login=marcin&token=abc123&key=k1&page=2';
		$args = ['cli.php', 'mail', '--to=anna@example.at', '--password=hunter2',
			'api_key', 'k-1', 'bob@x.co'];
		
		return $kept->removeFromUrl($url)
				=== '/unsubscribe/john@x.com/reset/[redacted]'
					. '?email=anna%40example.at&login=marcin&token=[redacted]&key=[redacted]&page=2'
			&& $kept->removeFromArgs($args)
				=== ['cli.php', 'mail', '--to=anna@example.at', '--password=[redacted]',
					'api_key', '[redacted]', 'bob@x.co']
			// the original logger is untouched
			&& $logger->removeFromUrl($url)
				=== '/unsubscribe/j***@x.com/reset/[redacted]'
					. '?email=a***@example.at&login=m***i*&token=[redacted]&key=[redacted]&page=2'
			&& $logger->removeFromArgs($args)
				=== ['cli.php', 'mail', '--to=a***@example.at', '--password=[redacted]',
					'api_key', '[redacted]', 'b**@x.co'];
	}
	
	/**
	 * The one name the copy still masks: a username field a project added
	 * with addUsernames(). codesafe recognises only its own username names
	 * (CODESAFE_USERNAMES, its Scrubber::USERNAME_PATTERNS), so a value under
	 * `nick` would be stored there in clear — in the bags and in a url alike.
	 * The names codesafe knows travel raw beside it
	 */
	public function keepingIdentitiesStillMasksAUsernameCodesafeCannotKnow(): bool
	{
		$kept = (new Subject)->addUsernames(['~^nick$~i'])->keepingIdentities();
		
		return $kept->remove(['nick' => 'marcin', 'username' => 'annab', 'nick_mail' => 'x'])
				=== ['nick' => 'm***i*', 'username' => 'annab', 'nick_mail' => 'x']
			&& $kept->removeFromUrl('/p?nick=marcin&user=annab')
				=== '/p?nick=m***i*&user=annab'
			// an address under the project's name is still an address: raw
			&& $kept->remove(['nick' => 'anna@example.at']) === ['nick' => 'anna@example.at']
			&& Subject::CODESAFE_USERNAMES === ['~^user([_-]?(name|login))?$~i', '~^login$~i'];
	}
	
	/**
	 * The LOCAL log file never reaches codesafe's vault, so it keeps masking:
	 * the request variables appended under a logged event read as masks
	 * whatever the Sender sends
	 */
	public function theLogFileStillMasksPeople(): bool
	{
		$get = $_GET;
		$post = $_POST;
		$_GET = ['email' => 'john.doe@example.com'];
		$_POST = ['username' => 'marcin', 'password' => 'hunter22'];
		try
		{
			$append = (new Subject)->getAppend();
		}
		finally
		{
			$_GET = $get;
			$_POST = $post;
		}
		
		return str_contains($append, 'j***.***@example.com')
			&& str_contains($append, '"m***i*"')
			&& str_contains($append, '[redacted]')
			&& str_contains($append, 'john.doe') === false
			&& str_contains($append, 'marcin') === false
			&& str_contains($append, 'hunter22') === false;
	}
}
