<?php
declare(strict_types=1);

namespace Tests\Service;

use Ovos\Service\Logger as Subject;
use Ovos\Test;

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

	public function unconfiguredNamesAndTokenShapesRemainIntact(): bool
	{
		$logger = new Subject;
		$data = ['username' => 'marcin', 'email' => 'anna@example.com', 'key' => 'abc', 'pin' => '1234'];
		$url = '/reset/eyJhbGciOiJIUzI1NiJ9.payload.signature?login=marcin&key=abc&pin=1234';
		return $logger->remove($data) === $data
			&& $logger->removeFromUrl($url) === $url
			&& $logger->removeText('user=marcin email=anna@example.com') === 'user=marcin email=anna@example.com';
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
}
