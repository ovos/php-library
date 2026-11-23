<?php
declare(strict_types=1);

namespace Tests\Translator;

use Ovos\Test;
use Ovos\Translator\Catalog as Subject;
use Ovos\Translator\Catalog\Exception;
use Ovos\Translator\Catalog\Problem;
use Ovos\Translator\MoWriter;
use Ovos\Translator\Po;
use Ovos\Translator\Po\Entry;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_key_last;
use function array_map;
use function bin2hex;
use function clearstatcache;
use function copy;
use function file_get_contents;
use function file_put_contents;
use function is_file;
use function mkdir;
use function random_bytes;
use function rmdir;
use function str_contains;
use function str_replace;
use function sys_get_temp_dir;
use function time;
use function touch;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * Translator\Catalog - a .po and its .mo checked and updated against the
 * code, the .mo by its content
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Catalog extends Test
{
	/**
	 * code/Page.php and translations/pl.po, one entry of each problem
	 */
	protected const string FILES = __DIR__ . '/files/catalog';
	
	/**
	 * What the fixture's .po has wrong whatever its .mo
	 */
	protected const array PROBLEMS = [
		'untranslated Deleted',
		'fuzzy Restored',
		'forms {n} file',
		'missing Sent',
		'missing Moved',
		'stale Archived',
	];
	
	/**
	 * A copy of the fixture to write into
	 */
	protected function copyFixture(): string
	{
		$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'catalog-' . bin2hex(random_bytes(6));
		mkdir($directory . '/code', 0777, true);
		mkdir($directory . '/translations');
		copy(self::FILES . '/code/Page.php', $directory . '/code/Page.php');
		copy(self::FILES . '/translations/pl.po', $directory . '/translations/pl.po');
		
		return $directory;
	}
	
	protected function remove(
		string $directory,
	): void
	{
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach($files as $file)
		{
			/**
			 * @var SplFileInfo $file
			 */
			$file->isDir()
				? rmdir($file->getPathname())
				: unlink($file->getPathname());
		}
		
		rmdir($directory);
	}
	
	/**
	 * @param Problem[] $problems
	 * @return string[]
	 */
	protected static function describe(
		array $problems,
	): array
	{
		return array_map(
			static fn(Problem $problem): string => $problem->msgid === null
				? $problem->kind
				: $problem->kind . ' ' . $problem->msgid,
			$problems,
		);
	}
	
	/**
	 * @param Entry[] $entries
	 * @return string[]
	 */
	protected static function getMsgids(
		array $entries,
	): array
	{
		return array_map(static fn(Entry $entry): string => $entry->msgid, $entries);
	}
	
	/**
	 * A .mo older than its .po but compiled from it is in line; one newer
	 * than its .po but with another translation is not
	 */
	public function checkNamesEachProblem(): bool
	{
		$directory = $this->copyFixture();
		$poPath = $directory . '/translations/pl.po';
		$moPath = Subject::getMoPath($poPath);
		
		try
		{
			(new MoWriter)->write(Po::fromFile($poPath), $moPath);
			touch($moPath, time() - 3600);
			touch($poPath, time());
			clearstatcache();
			$compiled = self::describe((new Subject)->check($poPath));
			
			$changed = Po::fromFile($poPath);
			$changed->getMessages()[0]->translations = ['Zapisane'];
			(new MoWriter)->write($changed, $moPath);
			touch($moPath, time() + 3600);
			clearstatcache();
			$other = self::describe((new Subject)->check($poPath));
		}
		finally
		{
			$this->remove($directory);
		}
		
		return $compiled === self::PROBLEMS
			&& $other === [...self::PROBLEMS, Problem::MO];
	}
	
	/**
	 * An update adds, retires and revives, writes both files, and a second
	 * update with nothing new writes nothing
	 */
	public function updateWritesOnlyWhatChanged(): bool
	{
		$directory = $this->copyFixture();
		$poPath = $directory . '/translations/pl.po';
		
		try
		{
			$subject = new Subject;
			$first = $subject->update($poPath);
			$revision = Po::fromFile($poPath)->getHeaderField('PO-Revision-Date');
			$problems = self::describe($subject->check($poPath));
			$second = $subject->update($poPath);
		}
		finally
		{
			$this->remove($directory);
		}
		
		return self::getMsgids($first['added']) === ['Sent']
			&& self::getMsgids($first['obsolete']) === ['Archived']
			&& self::getMsgids($first['revived']) === ['Moved']
			&& $first['written'] === [$poPath, Subject::getMoPath($poPath)]
			// Poedit sets the revision date on an update that changed something
			&& $revision !== '2026-01-02 03:04+0100'
			&& $problems === [
				'untranslated Deleted',
				'fuzzy Restored',
				'forms {n} file',
				'untranslated Sent',
			]
			&& $second['written'] === [];
	}
	
	/**
	 * Every msgid translated, none stale, the .mo compiled: nothing to say;
	 * a "#~" entry is no problem
	 */
	public function aCatalogInLineHasNoProblem(): bool
	{
		$directory = $this->copyFixture();
		$poPath = $directory . '/translations/pl.po';
		
		try
		{
			$subject = new Subject;
			$subject->update($poPath);
			
			$po = Po::fromFile($poPath);
			foreach($po->getMessages() as $entry)
			{
				$entry->setFuzzy(false);
				$entry->translations = $entry->plural === null
					? ['Przetłumaczone']
					: ['{n} plik', '{n} pliki', '{n} plików'];
			}
			
			$po->save($poPath);
			(new MoWriter)->write($po, Subject::getMoPath($poPath));
			$problems = $subject->check($poPath);
		}
		finally
		{
			$this->remove($directory);
		}
		
		return $problems === [];
	}
	
	public function aMissingMoIsAProblem(): bool
	{
		$directory = $this->copyFixture();
		$poPath = $directory . '/translations/pl.po';
		
		try
		{
			$problems = (new Subject)->check($poPath);
		}
		finally
		{
			$this->remove($directory);
		}
		
		$last = $problems[array_key_last($problems)] ?? null;
		
		return $last?->kind === Problem::MO
			&& $last->file === Subject::getMoPath($poPath)
			&& $last->detail === 'missing';
	}
	
	/**
	 * Sources that cannot be read retire nothing: update throws and writes
	 * nothing, check names the one problem
	 */
	public function unreadableSourcesLeaveTheFilesAlone(): bool
	{
		$directory = $this->copyFixture();
		$poPath = $directory . '/translations/pl.po';
		$before = (string)file_get_contents($poPath);
		file_put_contents($poPath, str_replace('X-Poedit-SearchPath-0: code', 'X-Poedit-SearchPath-0: gone', $before));
		$changed = (string)file_get_contents($poPath);
		
		try
		{
			$subject = new Subject;
			try
			{
				$subject->update($poPath);
				$thrown = false;
			}
			catch(Exception)
			{
				$thrown = true;
			}
			
			$after = (string)file_get_contents($poPath);
			$moWritten = is_file(Subject::getMoPath($poPath));
			$problems = self::describe($subject->check($poPath));
		}
		finally
		{
			$this->remove($directory);
		}
		
		return $thrown
			&& $after === $changed
			&& $moWritten === false
			&& $problems === [Problem::REFUSED];
	}
	
	/**
	 * A msgid twice in the .po, "#~" or not, is refused as gettext refuses it
	 */
	public function aDuplicateMsgidIsRefused(): bool
	{
		$directory = $this->copyFixture();
		$poPath = $directory . '/translations/pl.po';
		$before = (string)file_get_contents($poPath);
		$duplicated = str_replace('#~ msgid "Moved"', '#~ msgid "Saved"', $before);
		file_put_contents($poPath, $duplicated);
		
		try
		{
			$subject = new Subject;
			$problems = $subject->check($poPath);
			try
			{
				$subject->update($poPath);
				$thrown = false;
			}
			catch(Exception)
			{
				$thrown = true;
			}
			
			$after = (string)file_get_contents($poPath);
		}
		finally
		{
			$this->remove($directory);
		}
		
		return $duplicated !== $before
			&& self::describe($problems) === [Problem::REFUSED]
			&& str_contains($problems[0]->detail, '"Saved"')
			&& $thrown
			&& $after === $duplicated;
	}
}
