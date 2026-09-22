<?php
declare(strict_types=1);

namespace Ovos\Service\Console;

use Ovos\Service\Logger;

use function array_key_last;
use function http_build_query;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function json_last_error;
use function mb_check_encoding;
use function mb_strlen;
use function mb_substr;
use function parse_str;
use function preg_match;
use function preg_replace_callback;
use function str_contains;
use function stripos;
use function strlen;
use function strtolower;
use function trim;

use const JSON_ERROR_NONE;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * The raw request body, reduced before it leaves the application.
 *
 * The body is logged so the console can REPLAY the request that failed
 * (docs/SENDER.md §context.request): a JSON API call's $_POST is EMPTY,
 * because the body is a stream PHP never populates, so without it a replay is
 * a bare method and URL — a different request wearing the same name.
 *
 * Until 2026-09-21 it was scrubbed by the same flat `<name> = <value>` search
 * as free text, which is the mistake this class ends: a body is a structured
 * DOCUMENT. Six holes in that search were all the same mistake in different
 * punctuation, and the array walk beside it (Logger::remove) had none of them,
 * because it walks KEYS.
 *
 * So: parse, hand the tree to the key walk, run the text scrubber over what is
 * left inside the string values, and re-encode. Anything that will not parse
 * is DROPPED rather than handed back to the search.
 *
 * The Logger is passed in rather than reached for: it carries the project's
 * own addRemove()/addUsernames() extensions, so a body must be scrubbed by
 * THIS application's list and not a default one.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
final class Body
{
	/** no body leaves the application at all */
	public const string MODE_OFF = 'off';
	
	/** parsed, key-scrubbed, re-encoded — the default */
	public const string MODE_STRUCTURE = 'structure';
	
	/** the raw body as text, cut at FULL_MAX — the pre-2026-09-21 behaviour */
	public const string MODE_FULL = 'full';
	
	public const array MODES = [self::MODE_OFF, self::MODE_STRUCTURE, self::MODE_FULL];
	
	/**
	 * What `structure` sends. A document over the cap is SHRUNK — trailing
	 * keys go and `_truncated` says so — never cut mid-token, because it has
	 * to keep parsing to be read or replayed at all.
	 */
	public const int STRUCTURE_MAX = 8192;
	
	/** what `full` sends — the cap this sender has always applied */
	public const int FULL_MAX = 16384;
	
	/** well under json_decode's 512 default: the key walk caps the depth anyway */
	public const int JSON_DEPTH = 32;
	
	/**
	 * Endpoints that exist to RECEIVE credentials. Their body never leaves the
	 * application, whatever it parses as and whatever mode is configured.
	 *
	 * Applied here so the body never leaves, AND again at the console on
	 * write — because a deployment that never updates this library is the one
	 * case a client-side fix cannot reach.
	 */
	public const array CREDENTIAL_ROUTES = [
		'wp-login.php',
		'xmlrpc.php',
		'/wp-json/jwt-auth/',
		'/oauth/token',
		'/oauth2/token',
		'/login',
		'/signin',
		'/sign-in',
		'/session',
		'/register',
		'/password/reset',
		'/password-reset',
		'/user/password',
		'/auth/token',
	];
	
	/**
	 * A `<string>` in an XML-RPC call, and the one form of it that is KEPT:
	 * the inner method name of a system.multicall, which is the attack signal
	 * rather than a credential. The alternation is ordered so the kept form
	 * wins, so this is one pass and needs no sentinel.
	 */
	protected const string RPC_STRING = '~(<name>\s*methodName\s*</name>\s*<value>\s*<string>)'
		. '([^<]*)(</string>)|<string>([^<]*)</string>~i';
	
	/**
	 * The body, reduced per $mode, or null when nothing may be sent
	 */
	public static function redact(
		Logger $logger,
		string $raw,
		string $contentType,
		string $mode = self::MODE_STRUCTURE,
		string $uri = '',
	): ?string
	{
		if($mode === self::MODE_OFF
			|| trim($raw) === ''
			|| self::isCredentialRoute($uri) === true)
		{
			return null;
		}
		
		$type = strtolower(trim($contentType));
		
		// an upload's body is megabytes of binary and $_POST already carries
		// its fields
		if(stripos($type, 'multipart/form-data') !== false)
		{
			return null;
		}
		
		// a body we cannot even read as text is one we cannot promise anything
		// about
		if(mb_check_encoding($raw, 'UTF-8') === false || str_contains($raw, "\0") === true)
		{
			return null;
		}
		
		if($mode === self::MODE_FULL)
		{
			return $logger->removeText(mb_substr($raw, 0, self::FULL_MAX));
		}
		
		return self::structure($logger, $raw, $type);
	}
	
	/**
	 * Whether a URI names an endpoint whose body never leaves. Matched as a
	 * substring on purpose: a route may be mounted under any prefix, and a
	 * false positive costs one body while a miss costs a credential.
	 */
	public static function isCredentialRoute(
		string $uri,
	): bool
	{
		if($uri === '')
		{
			return false;
		}
		
		$path = strtolower($uri);
		
		foreach(self::CREDENTIAL_ROUTES as $route)
		{
			if(str_contains($path, $route) === true)
			{
				return true;
			}
		}
		
		return false;
	}
	
	/**
	 * Whether $mode is one this class knows, so a configured value it does not
	 * recognise cannot turn into "no mode" and take the decision with it
	 */
	public static function mode(
		string $mode,
	): string
	{
		$mode = trim($mode);
		
		return in_array($mode, self::MODES, true) === true ? $mode : self::MODE_STRUCTURE;
	}
	
	protected static function structure(
		Logger $logger,
		string $raw,
		string $type,
	): ?string
	{
		if($type === '' || str_contains($type, 'json') === true)
		{
			return self::json($logger, $raw);
		}
		
		if(str_contains($type, 'x-www-form-urlencoded') === true)
		{
			return self::form($logger, $raw);
		}
		
		if(str_contains($type, 'xml') === true)
		{
			return self::xml($logger, $raw);
		}
		
		return self::fit($logger->removeText(mb_substr($raw, 0, self::STRUCTURE_MAX)));
	}
	
	protected static function json(
		Logger $logger,
		string $raw,
	): ?string
	{
		$decoded = json_decode($raw, true, self::JSON_DEPTH);
		
		if(json_last_error() !== JSON_ERROR_NONE)
		{
			return null; // truncated, malformed, or not JSON after all
		}
		
		if(is_array($decoded) === true)
		{
			return self::encode(self::scrubStrings($logger, $logger->remove($decoded)));
		}
		
		// a bare JSON scalar: a string can hide a credential, a number cannot
		return is_string($decoded)
			? self::encode($logger->removeText($decoded))
			: self::fit($raw);
	}
	
	/**
	 * parse_str gives the same nested array PHP would have built in $_POST, so
	 * `opts[api_key]` becomes a real key and the walk finds it — and the values
	 * arrive DECODED, which is the other half of why parsing matters: the text
	 * search never decodes, so `note=password%3A%20hunter2` carries no
	 * separator for it to see and travelled whole.
	 */
	protected static function form(
		Logger $logger,
		string $raw,
	): ?string
	{
		parse_str($raw, $fields);
		
		if($fields === [])
		{
			return null;
		}
		
		return self::shrink(
			self::scrubStrings($logger, $logger->remove($fields)),
			static fn(array $data): string => http_build_query($data),
		);
	}
	
	/**
	 * An XML body, deliberately NOT handed to a real parser: this string is
	 * attacker-controlled and arrives in the application's own request, so
	 * pointing libxml at it buys entity expansion and a parser CVE surface in
	 * exchange for tidier output. The text scrubber's XML pass reads
	 * <password>x</password> including a namespace prefix, which is the whole
	 * of what a name-based rule can do for XML.
	 *
	 * XML-RPC is the exception that needs structure, because it NAMES nothing:
	 * the password is the second positional <string>. Reduced to its shape —
	 * every <string> becomes its length while the method names stay. A
	 * system.multicall carrying two hundred wp.getUsersBlogs IS the signal.
	 */
	protected static function xml(
		Logger $logger,
		string $raw,
	): ?string
	{
		$body = mb_substr($raw, 0, self::STRUCTURE_MAX);
		
		if(preg_match('~<methodCall[\s>]~i', $body) === 1)
		{
			$body = (string)preg_replace_callback(
				self::RPC_STRING,
				static function(array $match): string
				{
					// the kept form: an inner method name, not a credential
					if(($match[1] ?? '') !== '')
					{
						return $match[0];
					}
					
					return '<string>[redacted:' . mb_strlen($match[4] ?? '') . ']</string>';
				},
				$body,
			);
		}
		
		return self::fit($logger->removeText($body));
	}
	
	/**
	 * The text scrubber over every string VALUE in the tree, after the key walk
	 * has had it. Both are needed: the key walk answers a field NAMED like a
	 * credential, this answers one hiding inside an ordinary field's free text
	 * — a GraphQL mutation under `query`, a DSN in a `config` string.
	 *
	 * @param array<array-key, mixed> $data
	 * @return array<array-key, mixed>
	 */
	protected static function scrubStrings(
		Logger $logger,
		array $data,
	): array
	{
		foreach($data as $key => $value)
		{
			if(is_array($value) === true)
			{
				$data[$key] = self::scrubStrings($logger, $value);
				continue;
			}
			
			if(is_string($value) === true)
			{
				$data[$key] = $logger->removeText($value);
			}
		}
		
		return $data;
	}
	
	/**
	 * @param array<array-key, mixed>|string $data
	 */
	protected static function encode(
		array|string $data,
	): ?string
	{
		if(is_string($data) === true)
		{
			return self::fit($data);
		}
		
		return self::shrink($data, static function(array $data): ?string
		{
			$json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			
			return $json === false ? null : $json;
		});
	}
	
	/**
	 * Serialise, and while the result is over the cap drop the last top-level
	 * key and say so. Shared with the form path, which cannot be cut as a
	 * string either: cut mid-token its last parameter is corrupt, and a replay
	 * would post that.
	 *
	 * @param array<array-key, mixed> $data
	 * @param callable(array<array-key, mixed>): ?string $serialise
	 */
	protected static function shrink(
		array $data,
		callable $serialise,
	): ?string
	{
		$out = $serialise($data);
		
		while($out !== null && strlen($out) > self::STRUCTURE_MAX)
		{
			// the marker is re-added each round, so take it off before asking
			// which key is last, or it would be the one dropped
			unset($data['_truncated']);
			$last = array_key_last($data);
			
			if($last === null)
			{
				return null;
			}
			
			unset($data[$last]);
			$data['_truncated'] = true;
			$out = $serialise($data);
		}
		
		return $out === null || trim($out) === '' ? null : $out;
	}
	
	protected static function fit(
		?string $body,
	): ?string
	{
		if($body === null || trim($body) === '')
		{
			return null;
		}
		
		return mb_substr($body, 0, self::STRUCTURE_MAX);
	}
}
