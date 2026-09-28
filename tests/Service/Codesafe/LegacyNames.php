<?php
declare(strict_types=1);

namespace Tests\Service\Codesafe;

use Ovos\Application;
use Ovos\ArrayObject;
use Ovos\Container;
use Ovos\Controller;
use Ovos\Service\Codesafe\Sender;
use Ovos\Services;
use Ovos\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;

use function class_exists;
use function file_get_contents;
use function preg_match_all;
use function str_ends_with;
use function str_replace;

use const DIRECTORY_SEPARATOR;

/**
 * The sender's names from before the rename (2026-09-24).
 *
 * Service\Console and Plugins\Console moved to Service\Codesafe and
 * Plugins\Codesafe. Every application that reports to codesafe names them —
 * `- Console\Sender` in its service lists, `- Console\Shield` in its plugin
 * list, a `use` line — and has to keep working until it is changed, with the
 * SAME classes: a copy under the old name would be a second Sender with its
 * own queue, and an event sent twice or not at all.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class LegacyNames extends Test
{
	protected const array SERVICES = ['Body', 'Event', 'Otlp', 'Payload', 'Rollup', 'Sender', 'Shield', 'SourceContext', 'Untracked', 'Writer'];
	
	/**
	 * RULE: every class of Service\Codesafe answers under Service\Console
	 * through the autoloader, and is the new class, not a copy.
	 * No file sits at an old path: Ovos\Codesafe\Legacy answers them.
	 * Falsify: drop an entry from Legacy::NAMESPACES.
	 */
	public function theOldServiceNamesAreTheNewClasses(): bool
	{
		foreach(self::SERVICES as $name)
		{
			$old = 'Ovos\\Service\\Console\\' . $name;
			if(class_exists($old) === false
				|| (new ReflectionClass($old))->getName() !== 'Ovos\\Service\\Codesafe\\' . $name
			)
			{
				return false;
			}
		}
		
		return true;
	}
	
	/**
	 * RULE: a service list saying `Console\Sender` resolves to the Sender
	 * the new name resolves to.
	 */
	public function aServiceListNamingConsoleSenderGetsTheSender(): bool
	{
		$old = Application::resolveServiceClass('Console\\Sender');
		
		return (new ReflectionClass($old))->getName() === Application::resolveServiceClass('Codesafe\\Sender');
	}
	
	/**
	 * RULE: the Sender is services()->codesafeSender, and services()->consoleSender
	 * is the same instance — not a Disabled service, not a second Sender.
	 * Falsify: drop the registerCallable in Sender::register.
	 */
	public function theOldServiceKeyIsTheSameSender(): bool
	{
		$container = new Container();
		(new Services($container))->register(Sender::SYMBOL, Sender::class);
		// a real Sender autowires an Application into a bare container — the
		// instance under the new key is stood in for; the alias is what is tested
		$sender = (new ReflectionClass(Sender::class))->newInstanceWithoutConstructor();
		$container->registerCallable(Sender::SYMBOL, static fn(): Sender => $sender, overwrite: true);
		$services = new Services($container);
		
		return Sender::SYMBOL === 'codesafeSender'
			&& $services->codesafeSender === $sender
			&& $services->consoleSender === $sender;
	}
	
	/**
	 * RULE: a plugin list saying `Console\Shield` resolves to the Shield
	 * plugin the new name resolves to.
	 */
	public function aPluginListNamingConsoleShieldGetsTheShield(): bool
	{
		$old = Controller::resolvePluginClass('Console\\Shield');
		
		return (new ReflectionClass($old))->getName() === 'Ovos\\Plugins\\Codesafe\\Shield'
			&& Controller::resolvePluginClass('Codesafe\\Shield') === 'Ovos\\Plugins\\Codesafe\\Shield';
	}
	
	/**
	 * RULE: the sender reads `codesafe:`, and `console:` only when there is
	 * no `codesafe:` — a deployment that renamed its section is never read
	 * from the old one.
	 * Falsify: swap the two sections in Sender::configOf.
	 */
	public function theSenderReadsCodesafeThenConsole(): bool
	{
		$both = Sender::configOf(new ArrayObject(['codesafe' => ['key' => 'new'], 'console' => ['key' => 'old']]));
		$old = Sender::configOf(new ArrayObject(['console' => ['key' => 'old']]));
		
		return $both?->key === 'new'
			&& $old?->key === 'old'
			&& Sender::configOf(new ArrayObject(['system' => []])) === null;
	}
	
	/**
	 * RULE: the constructor's injected section is the one configOf reads —
	 * the attribute and the helper cannot drift apart.
	 */
	public function theConstructorInjectsTheSameSections(): bool
	{
		$parameter = (new ReflectionMethod(Sender::class, '__construct'))->getParameters()[0];
		foreach($parameter->getAttributes() as $attribute)
		{
			if($attribute->getName() === 'Ovos\\Container\\FirstOf')
			{
				return $attribute->getArguments() === [Sender::CONFIG, Sender::CONFIG_LEGACY]
					&& [Sender::CONFIG, Sender::CONFIG_LEGACY] === ['codesafe', 'console'];
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
