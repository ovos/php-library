<?php
declare(strict_types=1);

namespace Ovos\Service;

use Ovos\Service;
use Ovos\Cache\Key;
use Ovos\Cache\Store\Apcu;

use function getenv;
use function substr;
use function strlen;

use const BASE_DIR;

/**
 * Memory
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Memory extends Service
{
	public const string SYMBOL = 'memory';
	
	protected ?Apcu $store = null;
	
	public function getStore(): Apcu
	{
		if($this->store === null)
		{
			$home = getenv('HOME', true);
			$baseDirHomeless = $home === false
				? BASE_DIR
				: substr(BASE_DIR, strlen($home));
			$this->store = new Apcu(Key::fromPath($baseDirHomeless)); // path as a prefix
		}
		
		return $this->store;
	}
}
