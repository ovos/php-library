<?php
declare(strict_types=1);

namespace Ovos\Console;

use Ovos\Terminal\Table as TerminalTable;

/**
 * Table
 *
 * @deprecated Use {@see \Ovos\Terminal\Table} instead. Kept temporarily so the
 *             existing call sites keep working while they migrate; it is removed
 *             once they all use Ovos\Terminal\Table.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Table extends TerminalTable
{
}
