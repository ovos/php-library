<?php
declare(strict_types=1);

namespace Tests\Service\Console;

use Ovos\Service\Console\Untracked as Pass;
use Ovos\Test;
use Ovos\Test\Internal;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function array_column;
use function array_filter;
use function array_map;
use function array_values;
use function count;
use function file_put_contents;
use function getmypid;
use function hrtime;
use function in_array;
use function is_dir;
use function mkdir;
use function preg_match;
use function rmdir;
use function sort;
use function str_ends_with;
use function str_repeat;
use function str_replace;
use function strlen;
use function sys_get_temp_dir;
use function unlink;

use const PHP_BINARY;

/**
 * Service\Console\Untracked — the working-copy pass (ovos/console
 * docs/plans/file-appearance-sensor.md, detector 5 and its modified half):
 * the working copy found at or above a directory, git's and svn's answers
 * parsed into clean relative paths of three kinds (untracked, modified,
 * missing), an unversioned svn directory opened, every path classified by
 * shape and reach, the untracked executables listed and the rest counted per
 * directory, the tracked changes always listed, the report in the console's
 * shape — and null, never a guess, when nothing can be known. The runner is
 * proven on real processes (this PHP binary).
 *
 * The fixture is a throwaway tree under the system temp dir, built before
 * each test and removed after it.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Untracked extends Test
{
	protected string $base = '';
	
	/** the fixture's working copy root */
	protected string $wc = '';
	
	#[Internal]
	public function prepare(): void
	{
		$this->base = str_replace('\\', '/', sys_get_temp_dir()) . '/ovos-untracked-' . getmypid();
		$this->wc = $this->base . '/wc';
		$this->remove($this->base);
		
		foreach(['wc/.git', 'wc/app/sub', 'wc/public/uploads', 'wc/tools', 'wc/var', 'wc/drop/inner', 'svn/.svn', 'plain/a'] as $dir)
		{
			mkdir($this->base . '/' . $dir, 0777, true);
		}
		foreach([
			'wc/public/x.php' => '<?php echo 1;',
			'wc/public/.user.ini' => 'auto_prepend_file=x',
			'wc/public/uploads/a.pdf' => str_repeat('a', 100),
			'wc/public/uploads/b.PDF' => str_repeat('b', 50),
			'wc/public/index.php' => '<?php // entry',
			'wc/public/app.js' => 'skim();',
			'wc/tools/cron.php' => '<?php',
			'wc/.htaccess' => 'Deny from all',
			'wc/var/dump.log' => 'x',
			'wc/drop/inner/y.php' => '<?php',
			'wc/drop/readme.txt' => 'hi',
			'plain/.git' => 'gitdir: ../elsewhere',
		] as $file => $contents)
		{
			file_put_contents($this->base . '/' . $file, $contents);
		}
	}
	
	#[Internal]
	public function deconstruct(): void
	{
		$this->remove($this->base);
	}
	
	protected function remove(
		string $dir,
	): void
	{
		if(is_dir($dir) === false)
		{
			return;
		}
		
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach($iterator as $entry)
		{
			$entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
		}
		rmdir($dir);
	}
	
	/**
	 * RULE: the working copy is the nearest `.git` (a directory, or a
	 * worktree's FILE) or `.svn` at or above the start, within the levels
	 * allowed — and nothing when none is
	 */
	public function theWorkingCopyIsFoundAtOrAboveTheStart(): bool
	{
		$fromDeep = Pass::root($this->wc . '/app/sub');
		$fromRoot = Pass::root($this->wc . '/');
		$tooDeep = Pass::root($this->wc . '/app/sub', 1);
		
		return $fromDeep === ['root' => $this->wc, 'vcs' => 'git']
			&& $fromRoot === ['root' => $this->wc, 'vcs' => 'git']
			&& $tooDeep === null
			&& Pass::detect($this->base . '/svn') === 'svn'
			&& Pass::detect($this->base . '/plain') === 'git'
			&& Pass::detect($this->base . '/plain/a') === ''
			&& Pass::root($this->base . '/plain/a', 0) === null;
	}
	
	/**
	 * RULE: git's -z answers and svn's status lines all become clean
	 * relative paths of the right kind — untracked from `?` / ls-files,
	 * modified from `M` (git also T and A; svn also A and R), missing from
	 * `D` / `!`, a property-only svn change no modification at all;
	 * backslashes turned, a `./` prefix dropped, duplicates folded; an
	 * absolute path, a traversal segment or a control character is refused,
	 * not repaired
	 */
	public function gitAndSvnOutputParseIntoCleanRelativePaths(): bool
	{
		$git = Pass::parseGit("public/x.php\0./var/dump.log\0var/dump.log\0\0../evil.php\0a/../b.php\0/abs.php\0ok/..\0C:/win.php\0bad\x01.php\0");
		$diff = Pass::parseGitDiff("M\0README.md\0D\0public/index.php\0T\0lib/link.php\0A\0staged.php\0M\0../evil.php\0www/x.php\0docs/a.pdf\0M\0README.md\0");
		$svn = Pass::parseSvn("?       drop\nMM      tracked.php\n M      propmod.php\n!       gone.php\nA       added.php\nR       replaced.php\nD       scheduled.php\n?       public\\x.php  \r\n        ?  not-a-status\n?\t\tspaced/name with space.pdf\nM     + keyword.php\n");
		
		return $git === ['public/x.php', 'var/dump.log']
			&& $diff === ['modified' => ['README.md', 'lib/link.php', 'staged.php'], 'missing' => ['public/index.php']]
			&& $svn === [
				'untracked' => ['drop', 'public/x.php', 'spaced/name with space.pdf'],
				'modified' => ['tracked.php', 'added.php', 'replaced.php', 'keyword.php'],
				'missing' => ['gone.php', 'scheduled.php'],
			];
	}
	
	/**
	 * RULE: shape and reach decide — an untracked executable under a web
	 * directory is urgent, elsewhere high; a server config file under a web
	 * directory is high, elsewhere info; anything else untracked is only
	 * counted (null); a modified tracked file follows the same two rules, a
	 * browser script under a web directory is high (the skimmer's shape) and
	 * everything else modified is info; '.' makes the whole working copy
	 * reachable
	 */
	public function shapeAndReachDecideTheDetectorAndTheTier(): bool
	{
		$web = Pass::webDirs(['public/', '\\www']);
		
		return $web === ['public', 'www']
			&& Pass::webDirs(['', '.', '/']) === ['.']
			&& Pass::classify('public/x.php', $web) === ['detector' => 'untracked', 'tier' => 'urgent']
			&& Pass::classify('www/deep/y.PHTML', $web) === ['detector' => 'untracked', 'tier' => 'urgent']
			&& Pass::classify('publicity/x.php', $web) === ['detector' => 'untracked', 'tier' => 'high']
			&& Pass::classify('tools/cron.php', $web) === ['detector' => 'untracked', 'tier' => 'high']
			&& Pass::classify('lib/a.inc', $web) === ['detector' => 'untracked', 'tier' => 'high']
			&& Pass::classify('public/.user.ini', $web) === ['detector' => 'untracked_config', 'tier' => 'high']
			&& Pass::classify('.HTACCESS', $web) === ['detector' => 'untracked_config', 'tier' => 'info']
			&& Pass::classify('public/uploads/a.pdf', $web) === null
			&& Pass::classify('x.php.jpg', $web) === null
			&& Pass::classify('tools/cron.php', ['.']) === ['detector' => 'untracked', 'tier' => 'urgent']
			&& Pass::classifyModified('public/index.php', $web) === 'urgent'
			&& Pass::classifyModified('lib/Model.php', $web) === 'high'
			&& Pass::classifyModified('public/.htaccess', $web) === 'high'
			&& Pass::classifyModified('.htaccess', $web) === 'info'
			&& Pass::classifyModified('public/app.js', $web) === 'high'
			&& Pass::classifyModified('public/checkout.html', $web) === 'high'
			&& Pass::classifyModified('assets/app.js', $web) === 'info'
			&& Pass::classifyModified('public/logo.png', $web) === 'info'
			&& Pass::classifyModified('README.md', $web) === 'info';
	}
	
	/**
	 * RULE: untracked executables and config files are listed by path with
	 * their size and mtime; every other untracked file is ONE info row per
	 * directory carrying the count, the bytes, the newest mtime and the
	 * extension histogram — never a file name; a modified tracked file is
	 * listed with its tier, a missing one as info without numbers; tier
	 * order, then path order; the cap keeps the head and counts the
	 * remainder per tier
	 */
	public function executablesAreListedAndTheRestIsCountedPerDirectory(): bool
	{
		$changes = [
			'untracked' => ['var/dump.log', 'public/uploads/b.PDF', 'tools/cron.php', 'public/x.php', 'public/uploads/a.pdf', '.htaccess', 'public/.user.ini', 'gone/never.pdf'],
			'modified' => ['public/index.php', 'public/app.js', 'README.md'],
			'missing' => ['lib/Guard.php'],
		];
		$built = Pass::findings($changes, $this->wc, ['public']);
		$rows = $built['findings'];
		$listed = array_column($rows, 'path');
		$byPath = static fn(string $path) => array_values(array_filter($rows, static fn(array $row): bool => $row['path'] === $path))[0] ?? [];
		$uploads = $byPath('public/uploads');
		$index = $byPath('public/index.php');
		$guard = $byPath('lib/Guard.php');
		
		$capped = Pass::findings($changes, $this->wc, ['public'], 2);
		
		return array_column($rows, 'tier') === ['urgent', 'urgent', 'high', 'high', 'high', 'info', 'info', 'info', 'info', 'info', 'info']
			&& $listed === ['public/index.php', 'public/x.php', 'public/.user.ini', 'public/app.js', 'tools/cron.php',
				'.htaccess', 'README.md', 'gone', 'lib/Guard.php', 'public/uploads', 'var']
			&& array_filter($listed, static fn(string $path): bool => str_ends_with($path, '.pdf') || str_ends_with($path, '.log')) === []
			&& $rows[1]['detector'] === 'untracked' && $rows[1]['size'] === 13 && $rows[1]['mtime'] > 0 && $rows[1]['detail'] === ''
			&& $rows[5]['detector'] === 'untracked_config' && $rows[5]['path'] === '.htaccess' && $rows[5]['detail'] === ''
			&& ($index['detector'] ?? '') === 'modified' && ($index['size'] ?? 0) === 14 && ($index['mtime'] ?? 0) > 0
			&& ($guard['detector'] ?? '') === 'missing' && ($guard['tier'] ?? '') === 'info' && $guard['size'] === null && $guard['mtime'] === null
			&& ($uploads['detector'] ?? '') === 'untracked_dir'
			&& ($uploads['detail'] ?? '') === '2 files · .pdf ×2'
			&& ($uploads['size'] ?? 0) === 150
			&& ($uploads['mtime'] ?? 0) > 0
			&& $byPath('gone')['detail'] === '1 file · .pdf ×1' && $byPath('gone')['size'] === 0 && $byPath('gone')['mtime'] === null
			&& $built['counts'] === ['urgent' => 2, 'high' => 3, 'info' => 6]
			&& $built['truncated'] === ['urgent' => 0, 'high' => 0, 'info' => 0]
			&& $built['dirs'] === 7
			&& $built['executable'] === 3
			&& $built['bytes'] === 13 + 100 + 50 + 5 + 13 + 1 + 19 + 14 + 7 + 0
			&& count($capped['findings']) === 2
			&& $capped['counts'] === ['urgent' => 2, 'high' => 3, 'info' => 6]
			&& $capped['truncated'] === ['urgent' => 0, 'high' => 3, 'info' => 6];
	}
	
	/**
	 * RULE: svn names an unversioned directory once — the pass opens it to
	 * its files (so a dropped directory shows its PHP); a file and a path
	 * that is not there stay as they are
	 */
	public function svnListsADirectoryOnceAndThePassOpensIt(): bool
	{
		$expanded = Pass::expand(['drop', 'public/x.php', 'missing/file.pdf'], $this->wc);
		$paths = $expanded['paths'];
		sort($paths);
		
		return $expanded['unreadable'] === 0
			&& $paths === ['drop/inner/y.php', 'drop/readme.txt', 'missing/file.pdf', 'public/x.php'];
	}
	
	/**
	 * RULE: the report is the console's integrity-scan shape — platform php,
	 * the sender named, one root area with the untracked/modified/missing
	 * counters, the working copy in the posture, the VCS directory reported
	 * exposed when a web directory holds it; git is asked twice (the
	 * untracked list and the diff) and BOTH must answer; svn once; null with
	 * nothing asked when the root is no working copy
	 */
	public function theReportIsTheConsolesShapeOrNothing(): bool
	{
		$asked = [];
		$scripted = static function(?string $others, ?string $diff, ?string $svn = null) use (&$asked): Pass
		{
			return new Pass(static function(array $command, string $cwd, int $timeoutMs) use ($others, $diff, $svn, &$asked): ?string
			{
				$asked[] = $command[0] . ($command[0] === 'git' ? '-' . (in_array('diff', $command, true) ? 'diff' : 'others') : '');
				if($timeoutMs !== 1000)
				{
					return null;
				}
				
				return match(true)
				{
					$command[0] === 'svn' => $svn,
					in_array('diff', $command, true) => $diff,
					default => $others,
				};
			});
		};
		
		$report = $scripted("public/x.php\0var/dump.log\0", "M\0public/index.php\0D\0lib/Guard.php\0")->scan($this->wc, ['timeout_ms' => 5]);
		$manual = $scripted("public/x.php\0", '')->scan($this->wc, ['mode' => 'manual', 'web' => ['.'], 'release' => 'r1', 'environment' => 'staging', 'timeout_ms' => 5]);
		$halfSilent = $scripted("public/x.php\0", null)->scan($this->wc, ['timeout_ms' => 5]);
		$silent = $scripted(null, "M\0a.php\0")->scan($this->wc, ['timeout_ms' => 5]);
		$svn = $scripted(null, null, "?       drop\nM       public/index.php\n!       lib/Guard.php\n")->scan($this->base . '/svn', ['timeout_ms' => 5]);
		$nowhere = $scripted("x.php\0", '')->scan($this->base . '/plain/a', ['timeout_ms' => 5]);
		
		return $report !== null
			&& $report['type'] === 'files' && $report['platform'] === 'php'
			&& preg_match('~^php-library/[0-9A-Za-z._-]{1,32}$~', $report['client']) === 1
			&& preg_match('~^[0-9A-Za-z._-]{1,32}$~', $report['core']) === 1
			&& $report['php'] === $report['core']
			&& $report['release'] === '' && $report['environment'] === ''
			&& preg_match('~^[0-9a-f]{16}$~', $report['scan']['id']) === 1
			&& $report['scan']['mode'] === 'background' && $report['scan']['complete'] === true
			&& $report['scan']['files'] === 4 && $report['scan']['dirs'] === 3 && $report['scan']['chunks'] === 1
			&& $report['scan']['counts'] === ['urgent' => 2, 'high' => 0, 'info' => 2]
			&& $report['scan']['truncated'] === ['urgent' => 0, 'high' => 0, 'info' => 0]
			&& $report['areas'] === ['root' => ['root' => $this->wc, 'files' => 4, 'dirs' => 3, 'executable' => 2, 'bytes' => 28, 'probed' => 0,
				'foreign' => 2, 'modified' => 1, 'missing' => 1]]
			&& array_map(static fn(array $row): array => [$row['detector'], $row['tier'], $row['area'], $row['path']], $report['findings'])
				=== [['modified', 'urgent', 'root', 'public/index.php'], ['untracked', 'urgent', 'root', 'public/x.php'],
					['missing', 'info', 'root', 'lib/Guard.php'], ['untracked_dir', 'info', 'root', 'var']]
			&& $report['posture'] === ['working_copy' => 'git', 'vcs_exposed' => []]
			&& $manual !== null
			&& $manual['scan']['mode'] === 'manual' && $manual['release'] === 'r1' && $manual['environment'] === 'staging'
			&& $manual['posture'] === ['working_copy' => 'git', 'vcs_exposed' => ['.git']]
			&& $manual['areas']['root']['modified'] === 0 && $manual['areas']['root']['missing'] === 0
			&& $halfSilent === null
			&& $silent === null
			&& $svn !== null
			&& $svn['posture']['working_copy'] === 'svn'
			&& $svn['areas']['root'] === ['root' => $this->base . '/svn', 'files' => 3, 'dirs' => 3, 'executable' => 1, 'bytes' => 0, 'probed' => 0,
				'foreign' => 1, 'modified' => 1, 'missing' => 1]
			// info rows sort by path: the folded '.' (svn's unversioned entry at the root) before lib/
			&& array_column($svn['findings'], 'detector') === ['modified', 'untracked_dir', 'missing']
			&& $nowhere === null
			// git: two asks for a full report, two for the manual one, two when the diff is silent, ONE when the list is (the diff is never asked for nothing); svn: one
			&& $asked === ['git-others', 'git-diff', 'git-others', 'git-diff', 'git-others', 'git-diff', 'git-others', 'svn']
			&& Pass::word('dev-release/8.5') === 'dev-release-8.5'
			&& Pass::word('') === 'dev'
			&& strlen(Pass::word(str_repeat('9', 40))) === 32;
	}
	
	/**
	 * RULE: the runner answers stdout on a zero exit — every byte of a large
	 * one, NULs included — and null on a non-zero exit, on a run past the
	 * budget (terminated, not waited for), and where the directory is not
	 * there; proven on real processes, this PHP binary
	 */
	public function theRunnerAnswersStdoutOnSuccessAndNothingOtherwise(): bool
	{
		$small = Pass::run([PHP_BINARY, '-n', '-r', 'echo "a\0b";'], $this->wc, 5000);
		$large = Pass::run([PHP_BINARY, '-n', '-r', 'echo str_repeat("x", 200000); fwrite(STDERR, str_repeat("e", 70000));'], $this->wc, 10000);
		$failed = Pass::run([PHP_BINARY, '-n', '-r', 'echo "partial"; exit(3);'], $this->wc, 5000);
		
		$clock = hrtime(true);
		$slow = Pass::run([PHP_BINARY, '-n', '-r', 'sleep(20);'], $this->wc, 500);
		$waited = (hrtime(true) - $clock) / 1_000_000_000;
		
		$nowhere = Pass::run([PHP_BINARY, '-n', '-r', 'echo 1;'], $this->base . '/no-such-dir', 1000);
		
		return $small === "a\0b"
			&& $large !== null && strlen($large) === 200000
			&& $failed === null
			&& $slow === null && $waited < 5
			&& $nowhere === null;
	}
}
