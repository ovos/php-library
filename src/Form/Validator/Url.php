<?php
declare(strict_types=1);

namespace Ovos\Form\Validator;

use Ovos\Form\Error;
use Ovos\Form\Validator;
use Ovos\Strings;

use function filter_var;
use function implode;
use function in_array;
use function is_scalar;
use function parse_url;
use function sprintf;
use function strtolower;

use const FILTER_VALIDATE_URL;
use const PHP_URL_HOST;
use const PHP_URL_SCHEME;

/**
 * Url — a syntactically valid absolute URL with an allowed scheme.
 *
 * The scheme list is the point. FILTER_VALIDATE_URL alone accepts
 * javascript:alert(1) and data:text/html,… quite happily — both of which are
 * URLs, and neither of which anything should ever store from a form and later
 * render into an href. So the default is https only, and http has to be asked
 * for: a field that takes a URL from a user is almost always one that will be
 * followed or linked.
 *
 * A host is required too, which is what separates https://example.com from
 * https:/// — FILTER_VALIDATE_URL passes several hostless shapes.
 *
 * An unset value passes (NotEmpty's job).
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Url extends Validator
{
	// Errors
	public const string ERROR_INVALID = 'url_invalid';
	
	public const string ERROR_SCHEME = 'url_scheme';
	
	/**
	 * @var string[]
	 */
	protected array $messages =
	[
		self::ERROR_INVALID => '"%s" is not a valid URL.',
		self::ERROR_SCHEME => '"%s" must start with %s.',
	];
	
	/**
	 * @param string[] $schemes lowercase, without the "://"
	 */
	public function __construct(
		protected array $schemes = ['https'],
		?string $message = null,
	)
	{
		if($message !== null)
		{
			$this->withMessage($message);
		}
	}
	
	public function isValid(
		mixed $value,
	): bool
	{
		if($value === null || $value === '')
		{
			return true;
		}
		
		if(is_scalar($value) === false
			|| filter_var((string)$value, FILTER_VALIDATE_URL) === false
			|| (string)parse_url((string)$value, PHP_URL_HOST) === '')
		{
			$this->fail(self::ERROR_INVALID);
			
			return false;
		}
		
		$scheme = strtolower((string)parse_url((string)$value, PHP_URL_SCHEME));
		if(in_array($scheme, $this->schemes, true) === false)
		{
			$this->fail(self::ERROR_SCHEME, implode(':// or ', $this->schemes) . '://');
			
			return false;
		}
		
		return true;
	}
	
	protected function fail(
		string $code,
		?string $detail = null,
	): void
	{
		$label = Strings::escapeForHtml($this->getElement()->getName());
		
		$this->addError(new Error($code, $detail === null
			? sprintf($this->getMessage($code), $label)
			: sprintf($this->getMessage($code), $label, $detail)));
	}
}
