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
	/**
	 * @var string
	 */
	public const MAILTO = '&#109;&#97;&#105;&#108;&#116;&#111;&#58;';
	
	/**
	 * @var array
	 */
	protected array $_cache = [];
	
	/**
	 * @param string $email
	 * @param bool $mailto
	 *
	 * @return string
	 */
	public function protect(string $email, bool $mailto = false): string
	{
		if(array_key_exists($email, $this->_cache) === false)
		{
			$this->_cache[$email] = Strings::entities($email);
		}
		
		return $mailto
			? self::MAILTO . $this->_cache[$email]
			: $this->_cache[$email];
	}
}
