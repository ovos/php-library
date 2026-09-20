<?php
declare(strict_types=1);

namespace Ovos\Migration;

use Ovos\Migration;

/**
 * A migration that is its two SQL files and nothing else.
 *
 * Most migrations have no PHP in them: the class exists only to say "run my
 * up half, then my down half", four lines that differ between migrations
 * only in the name above them. Where the runner finds a `<id>_<Name>_up.sql`
 * with no `<id>_<Name>.php` beside it, it runs the migration through this
 * class instead and tells it where its halves are ($sqlBase).
 *
 * A migration that has real work to do — a seed, a backfill, anything the
 * SQL cannot say — still writes its own class and extends Migration
 * directly. This one is for the rest.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Sql extends Migration
{
	public function up(): void
	{
		$this->upSql();
	}
	
	public function down(): void
	{
		$this->downSql();
	}
}
