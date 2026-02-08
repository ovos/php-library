<?php
declare(strict_types=1);

namespace Ovos\Container\Register;

use Attribute;

/**
 * TypeLazy
 *
 * @author Marcin Gil <mg@ovos.at>
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class TypeLazy extends TypeClass
{
}
