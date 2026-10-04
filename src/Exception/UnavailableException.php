<?php
declare(strict_types=1);

namespace Ovos\Exception;

/**
 * A dependency the request needs cannot be reached — the database, a
 * connection the code cannot work without. Not a fault in the code: the
 * same request can succeed once the dependency is back, so an HTTP answer
 * is a 503, never a 500, and never an empty result that reads as "nothing
 * there".
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class UnavailableException extends RuntimeException
{
}
