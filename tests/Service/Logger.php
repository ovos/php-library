<?php
declare(strict_types=1);

namespace Tests\Service;

use Ovos\ArrayObject;
use Ovos\Service\Logger as Subject;
use Ovos\Test;

use function in_array;
use function str_contains;

class Logger extends Test
{
	public function customPatternsApplyAcrossFormats(): bool
	{
		$logger = (new Subject)->addRemove(['~^private_note$~i']);
		return $logger->remove(['private_note' => ['secret'], 'public_note' => 'visible'])
				=== ['private_note' => '[redacted]', 'public_note' => 'visible']
			&& $logger->removeText('PRIVATE_NOTE="two words" public_note="visible"')
				=== 'PRIVATE_NOTE="[redacted]" public_note="visible"'
			&& $logger->removeText('<private_note>two words</private_note>') === '<private_note>[redacted]</private_note>'
			&& $logger->removeFromUrl('/p?private%5Fnote=secret&public_note=visible')
				=== '/p?private%5Fnote=[redacted]&public_note=visible'
			&& $logger->removeFromArgs(['--private_note=secret', 'public_note', 'visible'])
				=== ['--private_note=[redacted]', 'public_note', 'visible'];
	}
	
	/**
	 * By default identities stay as they are, and the query-only names are
	 * plain field names outside a URL: a cache key is not a credential.
	 */
	public function identitiesAndQueryOnlyFieldNamesRemainIntactByDefault(): bool
	{
		$logger = new Subject;
		$data = ['username' => 'marcin', 'email' => 'anna@example.com', 'key' => 'abc', 'pin' => '1234'];
		return $logger->remove($data) === $data
			&& $logger->removeText('user=marcin email=anna@example.com') === 'user=marcin email=anna@example.com'
			&& $logger->masksIdentities() === false;
	}
	
	public function quotedCredentialsAndNestedFieldNamesAreRedacted(): bool
	{
		$logger = new Subject;
		foreach([
			'{"password":"two secret words","email":"anna@example.com"}',
			'opts[api_key]="two secret words"',
			'{"query":"login(password: \\"two secret words\\")"}',
			'<password>two secret words</password>',
		] as $input)
		{
			if(str_contains($logger->removeText($input), 'two secret words'))
			{
				return false;
			}
		}
		return true;
	}
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
	
	public function redactsTheOtherCredentialNames(): bool
	{
		$secrets = ['passphrase', 'private_key', 'credentials', 'license_key', 'encryption_key', 'signing_key', 'consumer_key', '_csrf', 'xsrf',
			'mfa_code', 'otp_code', 'totp', '2fa', 'backup_code', 'authcode', 'auth', 'x-auth', 'session_id', 'PHPSESSID'];
		$words = ['otpaper', 'credit', 'licensee', 'encryption', 'backup', 'author', 'e02fa5'];
		$out = (new Subject)->remove(array_fill_keys([...$secrets, ...$words], 'v'));
		
		foreach($secrets as $name)
		{
			if($out[$name] !== '[redacted]')
			{
				return false;
			}
		}
		foreach($words as $name)
		{
			if($out[$name] !== 'v')
			{
				return false;
			}
		}
		
		
		$frame = 'at render (https://shop.test/js/app.e02fa5.js:2:5390)';
		
		return str_contains((new Subject)->removeText('mfa_code=123456&note=x'), '123456') === false
			&& (new Subject)->removeText($frame) === $frame;
	}
	
	public function redactsSecretsInsideARawBody(): bool
	{
		$logger = new Subject;
		$json = $logger->removeText('{"sku":"X-1","password":"hunter2","note":"bob@example.com"}');
		$form = $logger->removeText('q=shoes&access_token=abc123&page=2');
		$header = $logger->removeText('Authorization: Bearer abc.def.xyz');
		
		return str_contains($json, '"sku":"X-1"')
			&& str_contains($json, 'hunter2') === false
			&& str_contains($json, '[redacted]')
			
			&& str_contains($json, 'bob@example.com')
			&& str_contains($json, '@example.com')
			&& str_contains($form, 'q=shoes') && str_contains($form, 'page=2')
			&& str_contains($form, 'abc123') === false
			
			&& str_contains($header, 'abc.def.xyz') === false
			&& $header === 'Authorization: [redacted]'
			
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
		
		return $logger->removeText('Login::check() failed for user=marcin') === 'Login::check() failed for user=marcin'
			&& $logger->removeText('user: marcin') === 'user: marcin'
			&& $logger->removeText('user:marcin') === 'user:marcin'
			&& $logger->removeText('Token::verify() password: hunter22') === 'Token::verify() password: [redacted]'
			&& $logger->removeText('password:hunter22') === 'password:[redacted]';
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
			&& $out['nested']['email'] === 'deep@nest.io'
			&& $out['nested']['username'] === 'deepuser'
			&& $out['nested']['list'][0] === 'a@b.co'
			&& $out['nested']['list'][1] === 'plain'
			&& $out['nested']['list'][2] === 42;
	}
	
	public function addRemoveExtendsThePatterns(): bool
	{
		$logger = (new Subject)->addRemove(['~^custom_field$~i']);
		
		$out = $logger->remove([
			'custom_field' => 'sensitive',
			'password'     => 'x',
		]);
		
		return $out['custom_field'] === '[redacted]'
			&& $out['password'] === '[redacted]';
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
	
	public function dropsSecretKeysHoldingArraysWholesale(): bool
	{
		$out = (new Subject)->remove([
			'password' => ['a', 'b'],
			'token'    => ['x' => 'y'],
		]);
		
		return $out['password'] === '[redacted]'
			&& $out['token'] === '[redacted]';
	}
	
	public function redactsOnlyConfiguredNamesInUrls(): bool
	{
		$logger = new Subject;
		
		return $logger->removeFromUrl('/checkout?step=2&token=abc123&email=john%40x.com')
				=== '/checkout?step=2&token=[redacted]&email=john%40x.com'
			&& $logger->removeFromUrl('/account?login=marcin&page=3')
				=== '/account?login=marcin&page=3'
			&& $logger->removeFromUrl('/unsubscribe/john@x.com?a=1')
				=== '/unsubscribe/john@x.com?a=1'
			&& $logger->removeFromUrl('/plain/path') === '/plain/path';
	}
	
	public function redactsOnlyConfiguredNamesInArgs(): bool
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
			'bob@x.co',
		];
	}
	
	public function localLogsKeepIdentitiesAndRedactCredentials(): bool
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
		
		return str_contains($append, 'john.doe@example.com')
			&& str_contains($append, '"marcin"')
			&& str_contains($append, '[redacted]')
			&& str_contains($append, 'john.doe')
			&& str_contains($append, 'marcin')
			&& str_contains($append, 'hunter22') === false;
	}
	
	/**
	 * Single-use credentials travel in paths and are followed over GET, where
	 * no query rule sees them. The cases follow codesafe's shared corpus
	 * (looks-secret.json), which its parity check holds this rule to.
	 */
	public function redactsTokenShapedPathSegments(): bool
	{
		$logger = new Subject;
		
		return $logger->removeFromUrl('/reset/eyJhbGciOiJIUzI1NiJ9.payloadpayload.sigsig/')
				=== '/reset/[redacted]/'
			&& $logger->removeFromUrl('/invite/3f2504e0-4f89-11d3-9a0c-0305e82c3301')
				=== '/invite/[redacted]'
			&& $logger->removeFromUrl('/x/a1b2c3d4e5f6a7b8c9d0e1f2') === '/x/[redacted]'
			&& $logger->removeFromUrl('/x/Xk7Qm2Rt9Zp4Lw8Nv3Bc6Hj1Fd5Gy0As') === '/x/[redacted]'
			&& $logger->removeFromPath('/login/magic/9f86d081884c7d659a2feaa0c55ad015a3bf4f1b')
				=== '/login/magic/[redacted]'
			// a short value after a secret name, which no shape rule sees
			&& $logger->removeFromPath('/api/token/abc123/x') === '/api/token/[redacted]/x'
			&& $logger->removeFromPath('/settings/token/') === '/settings/token/';
	}
	
	/** A readable slug is not a secret, however long it is. */
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
	 * A content-hashed bundle was fetched without credentials, and its name is
	 * what a JS error is read by; a signed download link is still redacted.
	 */
	public function leavesHashedAssetFilenamesIntact(): bool
	{
		$logger = new Subject;
		
		foreach([
			'/base_bf6051fdf10449dbde8945baf61cdf14.1785056998.js',
			'/static/main.9f8e7d6c5b4a39281706f5e4d3c2b1a0.js.map',
			'/assets/index-DkL9mQxZ8vB2nR4tY7wA.js',
			'/fonts/fa-solid-900.d0b8e0c6f4a2b1937c5d8e2f.woff2',
		] as $url)
		{
			if($logger->removeFromUrl($url) !== $url)
			{
				return false;
			}
		}
		
		// an extension is not an escape hatch
		return $logger->removeFromUrl('/files/Ab3Cd4Ef5Gh6Ij7Kl8Mn9Op0Qr1St2.pdf')
			=== '/files/[redacted]';
	}
	
	/**
	 * ?key= is where reset tokens travel (wp-login.php?action=rp&key=…), sig=
	 * signs a download link: credentials as query names, never as field names.
	 */
	public function redactsQueryOnlyNamesInUrls(): bool
	{
		$logger = new Subject;
		
		return $logger->removeFromUrl('/wp-login.php?action=rp&key=Qw3rTy8ZxC1vB2nM4kL6&login=root')
				=== '/wp-login.php?action=rp&key=[redacted]&login=root'
			&& $logger->removeFromUrl('/dl?sig=k3J9%2BpQ2&file=r.pdf') === '/dl?sig=[redacted]&file=r.pdf'
			&& $logger->removeFromUrl('/cb?CODE=4%2F0Af&state=x&pin=4829&hash=5f4d')
				=== '/cb?CODE=[redacted]&state=x&pin=[redacted]&hash=[redacted]'
			&& $logger->remove(['key' => 'cache-v3'])['key'] === 'cache-v3';
	}
	
	public function addQueryNamesExtendsTheQueryNames(): bool
	{
		$logger = (new Subject)->addQueryNames(['ticket']);
		
		return $logger->removeFromUrl('/a?ticket=T-1&key=k&page=2') === '/a?ticket=[redacted]&key=[redacted]&page=2'
			&& in_array('ticket', $logger->getQueryNames(), true)
			&& $logger->remove(['ticket' => 'T-1']) === ['ticket' => 'T-1'];
	}
	
	public function maskingAnonymizesUsernameFieldsKeepingEveryFourthCharacter(): bool
	{
		$out = (new Subject)->maskingIdentities()->remove([
			'username'   => 'marcin',
			'user'       => 'bob',
			'login'      => 'alice',
			'user_name'  => 'carol',
			'user_login' => 'erin',
			'userId'     => '1234',
			'userAgent'  => 'Mozilla/5.0',
		]);
		
		return $out === [
			'username'   => 'm***i*',
			'user'       => 'b**',
			'login'      => 'a***e',
			'user_name'  => 'c***l',
			'user_login' => 'e***',
			'userId'     => '1234',
			'userAgent'  => 'Mozilla/5.0',
		];
	}
	
	/**
	 * An address is masked in any field and in free text, the domain kept and
	 * the mask as long as the local part; an address under a username name is
	 * still an address, and a second pass changes nothing.
	 */
	public function maskingMasksEmailsKeepingTheDomain(): bool
	{
		$logger = (new Subject)->maskingIdentities();
		$out = $logger->remove([
			'email'    => 'john.doe@example.com',
			'contact'  => 'support@gmail.com',
			'note'     => 'ping bob@x.co and jane+tag@sub.example.org please',
			'username' => 'stefa@example.at',
			'login'    => 'm@example.at',
			'user'     => 'j***.***@example.com',
			'password' => 'admin@corp.com',
		]);
		
		return $out === [
				'email'    => 'j***.***@example.com',
				'contact'  => 's***o**@gmail.com',
				'note'     => 'ping b**@x.co and j***+***@sub.example.org please',
				'username' => 's***a@example.at',
				'login'    => 'm@example.at',
				'user'     => 'j***.***@example.com',
				'password' => '[redacted]',
			]
			&& $logger->remove($out) === $out;
	}
	
	public function maskingReachesUrlsArgsAndText(): bool
	{
		$logger = (new Subject)->maskingIdentities();
		
		return $logger->removeFromUrl('/checkout?step=2&token=abc123&email=john%40x.com')
				=== '/checkout?step=2&token=[redacted]&email=j***@x.com'
			&& $logger->removeFromUrl('/account?login=marcin&page=3') === '/account?login=m***i*&page=3'
			&& $logger->removeFromUrl('/unsubscribe/john@x.com?a=1') === '/unsubscribe/j***@x.com?a=1'
			&& $logger->removeFromUrl('/unsubscribe/john@x.com') === '/unsubscribe/j***@x.com'
			&& $logger->removeFromUrl('/x?user=jo%40b.co&login=m%40example.at')
				=== '/x?user=j*@b.co&login=m%40example.at'
			&& $logger->removeFromArgs(['cli.php', 'notify', '--to=bob@x.co', 'password', 'hunter2'])
				=== ['cli.php', 'notify', '--to=b**@x.co', 'password', '[redacted]']
			&& $logger->removeText('user=marcin password=hunter22 mail bob@x.co')
				=== 'user=m***i* password=[redacted] mail b**@x.co';
	}
	
	public function addUsernamesExtendsThePatterns(): bool
	{
		$logger = (new Subject)->addUsernames(['~^nick$~i'])->maskingIdentities();
		
		return $logger->remove(['nick' => 'marcin', 'username' => 'bob'])
				=== ['nick' => 'm***i*', 'username' => 'b**']
			&& in_array('~^nick$~i', $logger->getUsernames(), true);
	}
	
	/**
	 * The copy for a destination that stores identities itself drops every
	 * secret as always (fields, text, query-only names, token paths, argv) and
	 * keeps people raw; the logger it was made from still masks.
	 */
	public function keepingIdentitiesDropsSecretsAndKeepsPeople(): bool
	{
		$logger = (new Subject)->maskingIdentities();
		$kept = $logger->keepingIdentities();
		$data = [
			'email' => 'john.doe@example.com',
			'username' => 'marcin',
			'password' => 'hunter22',
			'opts' => ['api_key' => 'k-1', 'contact' => 'bob@x.co'],
		];
		$url = '/unsubscribe/john@x.com/reset/eyJhbGciOiJIUzI1NiJ9.payloadpayload.sigsig'
			. '?email=anna%40example.at&login=marcin&token=abc123&key=k1&page=2';
		$args = ['cli.php', 'mail', '--to=anna@example.at', '--password=hunter2', 'api_key', 'k-1'];
		
		return $kept->remove($data) === [
				'email' => 'john.doe@example.com',
				'username' => 'marcin',
				'password' => '[redacted]',
				'opts' => ['api_key' => '[redacted]', 'contact' => 'bob@x.co'],
			]
			&& $kept->removeText('user=marcin password=hunter22 mail bob@x.co')
				=== 'user=marcin password=[redacted] mail bob@x.co'
			&& $kept->removeFromUrl($url)
				=== '/unsubscribe/john@x.com/reset/[redacted]'
					. '?email=anna%40example.at&login=marcin&token=[redacted]&key=[redacted]&page=2'
			&& $kept->removeFromArgs($args)
				=== ['cli.php', 'mail', '--to=anna@example.at', '--password=[redacted]', 'api_key', '[redacted]']
			&& $kept->masksIdentities() === false
			&& $logger->removeFromUrl($url)
				=== '/unsubscribe/j***@x.com/reset/[redacted]'
					. '?email=a***@example.at&login=m***i*&token=[redacted]&key=[redacted]&page=2'
			&& $logger->remove($data)['username'] === 'm***i*';
	}
	
	/**
	 * A copy given the username fields its destination knows keeps those raw
	 * and masks the others, in bags and URLs; an address stays an address.
	 */
	public function keepingIdentitiesMasksTheUsernameFieldsItWasNotGiven(): bool
	{
		$logger = (new Subject)->addUsernames(['~^nick$~i']);
		$kept = $logger->keepingIdentities(['~^user([_-]?(name|login))?$~i', '~^login$~i']);
		
		return $kept->remove(['nick' => 'marcin', 'username' => 'annab', 'nick_mail' => 'x'])
				=== ['nick' => 'm***i*', 'username' => 'annab', 'nick_mail' => 'x']
			&& $kept->removeFromUrl('/p?nick=marcin&user=annab') === '/p?nick=m***i*&user=annab'
			&& $kept->remove(['nick' => 'anna@example.at']) === ['nick' => 'anna@example.at']
			// without a list every username field stays raw
			&& $logger->keepingIdentities()->remove(['nick' => 'marcin']) === ['nick' => 'marcin'];
	}
	
	/** logger.identities sets the policy of the log file; keep is the default. */
	public function theIdentitiesSwitchSetsTheLogFilesPolicy(): bool
	{
		$get = $_GET;
		$_GET = ['email' => 'john.doe@example.com', 'username' => 'marcin', 'key' => 'k'];
		try
		{
			$appends = [];
			foreach([null, ['identities' => 'keep'], ['identities' => 'mask'], ['identities' => 'other']] as $config)
			{
				$logger = new Subject;
				(fn() => $this->config = $config === null ? null : new ArrayObject($config))->call($logger);
				$appends[] = [$logger->masksIdentities(), $logger->getAppend(), $logger->keepingIdentities()->masksIdentities()];
			}
		}
		finally
		{
			$_GET = $get;
		}
		
		[$none, $keep, $mask, $other] = $appends;
		
		return $none[0] === false && str_contains($none[1], 'john.doe@example.com') && str_contains($none[1], '"marcin"')
			&& $keep[0] === false && str_contains($keep[1], 'john.doe@example.com')
			&& $mask[0] === true && str_contains($mask[1], 'j***.***@example.com') && str_contains($mask[1], '"m***i*"')
			&& str_contains($mask[1], 'john.doe') === false
			&& $other[0] === false
			// a copy's word wins over the switch
			&& $mask[2] === false
			&& (new Subject)->maskingIdentities()->masksIdentities() === true;
	}
}
