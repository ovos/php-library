<?php
declare(strict_types=1);

/**
 * The Shield kernel under its name from before the rename (2026-09-24).
 *
 * The kernel is Ovos\Codesafe\Shield now (src/Codesafe/Shield/Kernel.php,
 * vendored byte-identical from ovos/codesafe client-php/Shield.php). Code
 * that still names Ovos\Console\Shield\Kernel reaches this file through the
 * autoloader, and gets the same classes under the old names — every class
 * the kernel file declares, because they were only ever defined together.
 */

namespace Ovos\Console\Shield;

use function class_alias;
use function class_exists;

if(class_exists(\Ovos\Codesafe\Shield\Kernel::class, false) === false)
{
	require_once __DIR__ . '/../../Codesafe/Shield/Kernel.php';
}

foreach(['Facts', 'Consent', 'Verdict', 'Ruleset', 'Store', 'Kernel'] as $name)
{
	if(class_exists(__NAMESPACE__ . '\\' . $name, false) === false)
	{
		class_alias('Ovos\\Codesafe\\Shield\\' . $name, __NAMESPACE__ . '\\' . $name);
	}
}
