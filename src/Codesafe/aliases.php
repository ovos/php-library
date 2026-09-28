<?php
declare(strict_types=1);

/**
 * Registers Ovos\Codesafe\Legacy (composer.json autoload.files): the names
 * from before the rename keep resolving, on every install.
 */

use Ovos\Codesafe\Legacy;

spl_autoload_register([Legacy::class, 'load']);
