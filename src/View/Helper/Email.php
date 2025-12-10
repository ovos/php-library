<?php
declare(strict_types=1);

namespace Ovos\View\Helper;

use Ovos\View\Helper;
use Ovos\Strings;

/**
 * Email
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Email extends Helper
{
	public const string MAILTO = '&#109;&#97;&#105;&#108;&#116;&#111;&#58;';
	
	protected array $cache = [];
	
	public function protect(
		string $email,
		bool $mailto = false,
	): string
	{
		if(array_key_exists($email, $this->cache) === false)
		{
			$this->cache[$email] = Strings::entities($email);
		}
		
		return $mailto
			? self::MAILTO . $this->cache[$email]
			: $this->cache[$email];
	}
}
