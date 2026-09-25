<?php
declare(strict_types=1);

namespace Tests\Console\Shield;

use Ovos\Test;
use ReflectionClass;

use function class_exists;

/**
 * The Shield kernel's names from before the rename (2026-09-24).
 *
 * The kernel moved from Ovos\Console\Shield to Ovos\Codesafe\Shield. An
 * application that names the old classes — a CMS adapter written against
 * the earlier tag, a test — has to keep working until it is changed, and it
 * has to get the SAME classes: a second copy under the old namespace would
 * split a store and its rules in two.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class LegacyNames extends Test
{
	/**
	 * RULE: every class the kernel declares is reachable under the old name
	 * through the autoloader, and is the new class, not a copy.
	 *
	 * Prevents: an upgrade of php-library breaking every caller of the old
	 * names at the first request.
	 */
	public function theOldNamesAreTheNewClasses(): bool
	{
		foreach(['Facts', 'Consent', 'Verdict', 'Ruleset', 'Store', 'Kernel'] as $name)
		{
			$old = 'Ovos\\Console\\Shield\\' . $name;
			$new = 'Ovos\\Codesafe\\Shield\\' . $name;
			if(class_exists($old) === false || (new ReflectionClass($old))->getName() !== $new)
			{
				return false;
			}
		}
		
		return true;
	}
}
