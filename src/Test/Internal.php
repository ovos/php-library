<?php
declare(strict_types=1);

namespace Ovos\Test;

use Attribute;

/**
 * Internal
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
#[Attribute(Attribute::TARGET_METHOD)]
class Internal
{
}

