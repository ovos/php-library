<?php
declare(strict_types=1);

namespace Tests\Service\Codesafe;

use Ovos\Application;
use Ovos\ArrayObject;
use Ovos\Controller;
use Ovos\Service\Codesafe\Sender;
use Ovos\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;

use function file_get_contents;
use function preg_match_all;
use function str_ends_with;
use function str_replace;

use const DIRECTORY_SEPARATOR;

/**
 * How an application reaches the codesafe sender: `- Codesafe\Sender` in its
 * service lists (services()->codesafeSender), `- Codesafe\Shield` in its
 * plugin list, the `codesafe:` config section — and the key the sender
 * sends under both header names.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Wiring extends Test
{
	/**
	 * RULE: a service list saying `Codesafe\Sender` resolves to the Sender,
	 * registered as codesafeSender.
	 */
	public function aServiceListNamingCodesafeSenderGetsTheSender(): bool
	{
		return Application::resolveServiceClass('Codesafe\\Sender') === Sender::class
			&& Sender::SYMBOL === 'codesafeSender';
	}
	
	/**
	 * RULE: a plugin list saying `Codesafe\Shield` resolves to the Shield plugin.
	 */
	public function aPluginListNamingCodesafeShieldGetsTheShield(): bool
	{
		return Controller::resolvePluginClass('Codesafe\\Shield') === 'Ovos\\Plugins\\Codesafe\\Shield';
	}
	
	/**
	 * RULE: the sender reads `codesafe:` and nothing else — a leftover
	 * `console:` section configures nothing.
	 * Falsify: point Sender::CONFIG at 'console'.
	 */
	public function theSenderReadsTheCodesafeSection(): bool
	{
		return Sender::configOf(new ArrayObject(['codesafe' => ['key' => 'new'], 'console' => ['key' => 'old']]))?->key === 'new'
			&& Sender::configOf(new ArrayObject(['console' => ['key' => 'old']])) === null;
	}
	
	/**
	 * RULE: the constructor's injected section is the one configOf reads —
	 * the attribute and the helper cannot drift apart.
	 */
	public function theConstructorInjectsTheSameSection(): bool
	{
		$parameter = (new ReflectionMethod(Sender::class, '__construct'))->getParameters()[0];
		foreach($parameter->getAttributes() as $attribute)
		{
			if($attribute->getName() === 'Ovos\\Container\\ArrayObject')
			{
				return $attribute->getArguments() === [Sender::CONFIG];
			}
		}
		
		return false;
	}
	
	/**
	 * RULE: the key goes under both header names, new first, and no sender
	 * spells a header itself — the Sender's two posts and the Rollup's all
	 * go through keyHeaders(). The kernel (src/Codesafe/Shield) is vendored
	 * byte for byte from codesafe and sends both on its own.
	 * Falsify: put 'X-Console-Key: ' back into Rollup::post().
	 */
	public function everySenderPostNamesTheKeyTwice(): bool
	{
		if(Sender::keyHeaders('k') !== ['X-Codesafe-Key: k', 'X-Console-Key: k'])
		{
			return false;
		}
		
		$dir = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..'
			. DIRECTORY_SEPARATOR . 'src';
		$spelled = 0;
		$calls = 0;
		foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)) as $file)
		{
			$path = str_replace('\\', '/', $file->getPathname());
			if(str_ends_with($path, '.php') === false || str_ends_with($path, 'Codesafe/Shield/Kernel.php'))
			{
				continue;
			}
			$code = (string)file_get_contents($path);
			$spelled += preg_match_all("/'X-(?:Console|Codesafe)-Key: ' \\./", $code);
			$calls += preg_match_all('/\bkeyHeaders\(\(string\)/', $code);
		}
		
		// the two lines of keyHeaders() itself; three posts call it
		return $spelled === 2 && $calls === 3;
	}
}
