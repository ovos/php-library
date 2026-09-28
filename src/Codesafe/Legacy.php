<?php
declare(strict_types=1);

namespace Ovos\Codesafe;

use function class_alias;
use function class_exists;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * The names from before the rename (2026-09-24): Ovos\Service\Console,
 * Ovos\Plugins\Console and Ovos\Console\Shield are Ovos\Service\Codesafe,
 * Ovos\Plugins\Codesafe and Ovos\Codesafe\Shield now.
 *
 * An application that still says `- Console\Sender` in its service list,
 * `- Console\Shield` in its plugin list or names an old class in a `use`
 * line keeps working, and gets the SAME class — a copy under the old name
 * would be a second Sender with its own queue, or a second Shield store.
 *
 * An autoloader, not a shim file at each old path: an install with
 * `--classmap-authoritative` (every prod:install) only loads what the
 * classmap lists, and a file that declares no class is never in it.
 * Composer stops looking after its own classmap, but the autoloaders
 * registered after it still run — this one, from autoload.files.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Legacy
{
	/** old namespace => new namespace */
	public const array NAMESPACES = [
		'Ovos\\Service\\Console\\' => 'Ovos\\Service\\Codesafe\\',
		'Ovos\\Plugins\\Console\\' => 'Ovos\\Plugins\\Codesafe\\',
		'Ovos\\Console\\Shield\\' => 'Ovos\\Codesafe\\Shield\\',
	];
	
	/**
	 * The autoloader: an old name becomes an alias of the new class
	 */
	public static function load(
		string $class,
	): void
	{
		foreach(self::NAMESPACES as $old => $new)
		{
			if(str_starts_with($class, $old) === false)
			{
				continue;
			}
			
			$target = $new . substr($class, strlen($old));
			// the kernel's six classes live in one file, Kernel.php — only
			// the classmap knows Facts or Store on its own
			if(str_starts_with($target, 'Ovos\\Codesafe\\Shield\\'))
			{
				class_exists(Shield\Kernel::class);
			}
			if(class_exists($target))
			{
				class_alias($target, $class);
			}
			
			return;
		}
	}
}
