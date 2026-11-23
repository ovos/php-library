<?php
declare(strict_types=1);

namespace Ovos\Translator\Catalog;

use RuntimeException;

/**
 * Exception - a .po or its sources that cannot be read or updated without
 * losing something; Catalog::update() writes nothing then and
 * Catalog::check() reports it
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Exception extends RuntimeException
{
}
