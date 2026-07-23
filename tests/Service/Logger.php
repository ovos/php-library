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
			if($value !== '[removed]')
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
		
		return $out['PASSWORD'] === '[removed]'
			&& $out['passwd'] === '[removed]'
			&& $out['user_password'] === '[removed]'
			&& $out['Authorization'] === '[removed]';
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
		
		return $out['password'] === '[removed]';
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
		
		return $out['nested']['password'] === '[removed]'
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
		
		return $out['custom_field'] === '[removed]'
			&& $out['password'] === '[removed]';
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
	public function redactsThePwdAbbreviation(): bool
	{
		$out = (new Subject)->remove([
			'pwd'         => 'hunter2',
			'confirm_pwd' => 'hunter2',
		]);
		
		return $out['pwd'] === '[removed]'
			&& $out['confirm_pwd'] === '[removed]';
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
		
		return $out['password'] === '[removed]'
			&& $out['token'] === '[removed]';
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
				=== '/checkout?step=2&token=[removed]&email=j***@x.com'
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
			'--api-key=[removed]',
			'password',
			'[removed]',
			'notify',
			'b***@x.co',
		];
	}
}
