<?php
declare(strict_types=1);

namespace Ovos\Service\Console;

use Composer\InstalledVersions;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

use function array_keys;
use function array_merge;
use function array_slice;
use function arsort;
use function basename;
use function bin2hex;
use function class_exists;
use function count;
use function dirname;
use function explode;
use function file_get_contents;
use function filemtime;
use function filesize;
use function function_exists;
use function hrtime;
use function implode;
use function in_array;
use function intdiv;
use function is_array;
use function is_dir;
use function is_file;
use function is_resource;
use function max;
use function mb_strlen;
use function mb_substr;
use function min;
use function preg_match;
use function preg_replace;
use function preg_split;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function random_bytes;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strrpos;
use function strtolower;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function time;
use function trim;
use function unlink;
use function usleep;
use function usort;

use const PHP_OS_FAMILY;
use const PHP_VERSION;

/**
 * The working-copy pass — what the deployed tree holds that the repository
 * did not ship (ovos/console docs/plans/file-appearance-sensor.md, detector
 * 5, and its modified half): a file the repository does not track, a tracked
 * file whose content differs from the commit, a tracked file that is gone.
 * The first is the one detector that sees a dropped file BEFORE anything runs
 * it; the second is where a payload written INTO an existing file shows. Asks
 * git or svn, read-only, and builds the integrity-scan report the console's
 * `POST /api/v1/ingest/files` takes from every sender (the same shape the
 * WordPress plugin's tree walk sends), so the ledger, the cases, the alert
 * and the attack-wave correlation apply unchanged.
 *
 * Read-only by contract, and careful with the working copy it reads:
 *
 *   git --no-optional-locks ls-files --others --exclude-standard -z
 *   git --no-optional-locks -c core.fileMode=false diff --no-renames --name-status -z HEAD
 *   svn status --non-interactive
 *
 * never `git status` (it refreshes and locks the index — a killed run leaves
 * `index.lock` for the customer's next deploy), never `svn status -u`, never
 * `svn cleanup`. `--no-optional-locks` keeps the diff from writing the index
 * too: a deploy that touched every file's mtime after checkout (a chmod -R)
 * leaves a stale stat cache, and then every pass re-hashes those files — run
 * `git update-index --refresh` once in such a deploy step. core.fileMode=false
 * so a mode change alone is not a modification. Ignored files stay out
 * (.gitignore / svn:ignore): measured on the console repo, 3 untracked
 * against 793 ignored — the ignored pass would drown the signal.
 *
 * What gets a path in the report, and what only a count: an untracked
 * executable-shaped file (.php, .phtml, .phar, .inc …) or server config file
 * (.htaccess, .user.ini, php.ini, web.config) is listed by path; every other
 * untracked file is counted PER DIRECTORY with an extension histogram,
 * because `uploads/offer-acme-gmbh.pdf` names a customer. A modified or
 * missing TRACKED file is always listed by path — its name is repository
 * content, not customer data. Under a web-reachable directory (config
 * `console.files.web`, default `public`) an untracked or modified PHP file is
 * URGENT, elsewhere HIGH; a config file there is HIGH, elsewhere info; a
 * modified script the browser runs (.js, .html, .svg) there is HIGH — a card
 * skimmer is exactly that; a missing tracked file is info.
 *
 * Every failure is "cannot know" — null, never a guess and never a warning:
 * no working copy, `proc_open` closed, a non-zero exit (git's `safe.directory`
 * refusal included), a run past the budget. Both git answers are needed: a
 * report built from one of them would mark the other half's findings GONE.
 *
 * CLI/cron only — Sender::untrackedReport() enforces it. Spawning git in a
 * web request costs latency and, on a big tree, the filesystem cache.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
final class Untracked
{
	/** the report's platform word — the console's closed vocabulary (Console\Ingest\FilesPayload::PLATFORMS) */
	public const string PLATFORM = 'php';
	
	/** the one area a working copy has: its root */
	public const string AREA = 'root';
	
	public const string GIT = 'git';
	
	public const string SVN = 'svn';
	
	/** an executable-shaped file the repository does not track — listed by path */
	public const string DETECTOR_FILE = 'untracked';
	
	/** a server config file the repository does not track — listed by path */
	public const string DETECTOR_CONFIG = 'untracked_config';
	
	/** every other untracked file, counted per directory — the path IS the directory */
	public const string DETECTOR_DIR = 'untracked_dir';
	
	/** a tracked file whose content differs from the commit — listed by path */
	public const string DETECTOR_MODIFIED = 'modified';
	
	/** a tracked file that is not on disk — listed by path */
	public const string DETECTOR_MISSING = 'missing';
	
	public const string TIER_URGENT = 'urgent';
	
	public const string TIER_HIGH = 'high';
	
	public const string TIER_INFO = 'info';
	
	public const array TIERS = [self::TIER_URGENT, self::TIER_HIGH, self::TIER_INFO];
	
	public const string MODE_BACKGROUND = 'background';
	
	public const string MODE_MANUAL = 'manual';
	
	/** the web-reachable directories, relative to the working copy root; '.' = the root itself is the docroot */
	public const array WEB_DEFAULT = ['public'];
	
	/** the report lists this many findings in tier order; the remainder is counted per tier */
	public const int MAX_FINDINGS = 200;
	
	/** an unversioned SVN directory is opened up to this many files */
	public const int MAX_EXPANDED = 10000;
	
	/** the console's cap on a finding's detail */
	public const int MAX_DETAIL = 160;
	
	public const int TIMEOUT_MS = 30000;
	
	/** how far above the start directory the working copy root may sit (the console keeps .git one level above project/) */
	public const int LEVELS_UP = 3;
	
	public const string EXECUTABLE = '~\.(?:php[3-8]?|phtml|phar|inc|pht|phps)$~i';
	
	/** what the browser runs — a modified one under a web directory is the skimmer's shape */
	public const string WEB_SCRIPT = '~\.(?:m?js|html?|svg)$~i';
	
	public const array CONFIG_NAMES = ['.htaccess', '.user.ini', 'php.ini', 'web.config'];
	
	public const array GIT_COMMAND = ['git', '--no-optional-locks', 'ls-files', '--others', '--exclude-standard', '-z'];
	
	public const array GIT_DIFF_COMMAND = ['git', '--no-optional-locks', '-c', 'core.fileMode=false', 'diff', '--no-renames', '--name-status', '-z', 'HEAD'];
	
	public const array SVN_COMMAND = ['svn', 'status', '--non-interactive'];
	
	public const string PACKAGE = 'ovos/php-library';
	
	/**
	 * Runs one command in a directory and answers its stdout, or null when it
	 * could not run, exited non-zero or ran past the budget
	 *
	 * @var callable(list<string> $command, string $cwd, int $timeoutMs): ?string
	 */
	protected $runner;
	
	public function __construct(
		?callable $runner = null,
	)
	{
		$this->runner = $runner ?? self::run(...);
	}
	
	/**
	 * The working copy at or above a directory: its root and which VCS holds
	 * it, or null when none does within the levels allowed
	 *
	 * @return array{root: string, vcs: string}|null
	 */
	public static function root(
		string $start,
		int $levels = self::LEVELS_UP,
	): ?array
	{
		$dir = rtrim(str_replace('\\', '/', $start), '/');
		
		for($level = 0; $level <= $levels; $level++)
		{
			if($dir === '')
			{
				break;
			}
			
			$vcs = self::detect($dir);
			if($vcs !== '')
			{
				return ['root' => $dir, 'vcs' => $vcs];
			}
			
			$parent = dirname($dir);
			if($parent === $dir)
			{
				break;
			}
			$dir = $parent;
		}
		
		return null;
	}
	
	/**
	 * Which VCS holds a directory: `.git` (a directory, or the FILE a worktree
	 * carries) is git, `.svn` is svn, neither is ''
	 */
	public static function detect(
		string $dir,
	): string
	{
		$dir = rtrim($dir, '/\\') . '/';
		
		try
		{
			if(is_dir($dir . '.git') || is_file($dir . '.git'))
			{
				return self::GIT;
			}
			if(is_dir($dir . '.svn'))
			{
				return self::SVN;
			}
		}
		catch(Throwable)
		{
			// an unreadable path is no working copy
		}
		
		return '';
	}
	
	/**
	 * The pass: ask the working copy, classify what it answers, build the
	 * console's report — or null when nothing can be known
	 *
	 * @param array{vcs?: string, web?: list<string>, mode?: string, release?: string,
	 *   environment?: string, timeout_ms?: int} $options
	 */
	public function scan(
		string $root,
		array $options = [],
	): ?array
	{
		$root = rtrim(str_replace('\\', '/', $root), '/');
		$vcs = (string)($options['vcs'] ?? self::detect($root));
		if($vcs !== self::GIT && $vcs !== self::SVN)
		{
			return null;
		}
		
		$started = time();
		$clock = hrtime(true);
		$timeout = max(1000, (int)($options['timeout_ms'] ?? self::TIMEOUT_MS));
		
		$unreadable = 0;
		if($vcs === self::GIT)
		{
			$others = ($this->runner)(self::GIT_COMMAND, $root, $timeout);
			$diff = $others === null ? null : ($this->runner)(self::GIT_DIFF_COMMAND, $root, $timeout);
			if($others === null || $diff === null)
			{
				return null;
			}
			
			$changes = ['untracked' => self::parseGit($others)] + self::parseGitDiff($diff);
		}
		else
		{
			$status = ($this->runner)(self::SVN_COMMAND, $root, $timeout);
			if($status === null)
			{
				return null;
			}
			
			$changes = self::parseSvn($status);
			$expanded = self::expand($changes['untracked'], $root);
			$changes['untracked'] = $expanded['paths'];
			$unreadable = $expanded['unreadable'];
		}
		
		$web = self::webDirs(is_array($options['web'] ?? null) ? $options['web'] : self::WEB_DEFAULT);
		$built = self::findings($changes, $root, $web);
		$files = count($changes['untracked']) + count($changes['modified']) + count($changes['missing']);
		
		return [
			'v' => 1,
			'type' => 'files',
			'platform' => self::PLATFORM,
			'core' => self::word(PHP_VERSION),
			'php' => self::word(PHP_VERSION),
			'client' => self::client(),
			'release' => (string)($options['release'] ?? ''),
			'environment' => (string)($options['environment'] ?? ''),
			'scan' => [
				'id' => bin2hex(random_bytes(8)),
				'mode' => ($options['mode'] ?? '') === self::MODE_MANUAL ? self::MODE_MANUAL : self::MODE_BACKGROUND,
				'started' => $started,
				'finished' => time(),
				'duration' => intdiv(hrtime(true) - $clock, 1_000_000),
				'chunks' => 1,
				'complete' => true,
				'files' => $files,
				'dirs' => $built['dirs'],
				'unreadable' => $unreadable,
				'symlinks' => 0,
				'skipped' => [],
				'counts' => $built['counts'],
				'truncated' => $built['truncated'],
			],
			'areas' => [
				self::AREA => [
					'root' => $root,
					'files' => $files,
					'dirs' => $built['dirs'],
					'executable' => $built['executable'],
					'bytes' => $built['bytes'],
					'probed' => 0,
					// the checksum pass's words, which fit: foreign = the repository never shipped it
					'foreign' => count($changes['untracked']),
					'modified' => count($changes['modified']),
					'missing' => count($changes['missing']),
				],
			],
			'findings' => $built['findings'],
			'posture' => [
				'working_copy' => $vcs,
				'vcs_exposed' => self::exposed($root, $vcs, $web),
			],
		];
	}
	
	/**
	 * git's `ls-files -z` answer: NUL-separated paths, forward slashes, no
	 * quoting
	 *
	 * @return list<string>
	 */
	public static function parseGit(
		string $output,
	): array
	{
		$paths = [];
		foreach(explode("\0", $output) as $path)
		{
			$clean = self::cleanPath($path);
			if($clean !== null)
			{
				$paths[$clean] = true;
			}
		}
		
		return array_keys($paths);
	}
	
	/**
	 * git's `diff --name-status -z HEAD` answer: status, NUL, path, NUL —
	 * M (and T, a type change, or A, staged but never committed) is a
	 * tracked file that differs from the commit, D one that is gone; a pair
	 * whose status is not a letter is skipped, never guessed at
	 *
	 * @return array{modified: list<string>, missing: list<string>}
	 */
	public static function parseGitDiff(
		string $output,
	): array
	{
		$modified = [];
		$missing = [];
		$tokens = explode("\0", $output);
		
		for($index = 0; $index + 1 < count($tokens); $index += 2)
		{
			$status = $tokens[$index];
			if(preg_match('~^[A-Z]~', $status) !== 1)
			{
				continue;
			}
			
			$clean = self::cleanPath($tokens[$index + 1]);
			if($clean === null)
			{
				continue;
			}
			
			switch($status[0])
			{
				case 'M':
				case 'T':
				case 'A':
					$modified[$clean] = true;
					break;
				case 'D':
					$missing[$clean] = true;
					break;
			}
		}
		
		return ['modified' => array_keys($modified), 'missing' => array_keys($missing)];
	}
	
	/**
	 * svn's status lines, by the FIRST column only: `?` untracked, `M` (or
	 * `A`, `R`) a tracked file whose content differs, `!` (or `D`) a tracked
	 * file that is gone; a property-only change (second column) is no
	 * modification. The path follows the seven status columns; backslashes
	 * (a Windows checkout) become slashes.
	 *
	 * @return array{untracked: list<string>, modified: list<string>, missing: list<string>}
	 */
	public static function parseSvn(
		string $output,
	): array
	{
		$rows = ['untracked' => [], 'modified' => [], 'missing' => []];
		
		foreach(preg_split('~\r\n|\n|\r~', $output) ?: [] as $line)
		{
			if(preg_match('#^([?MAR!D])[ A-Z+~]{0,6}\s+(.+?)\s*$#', $line, $match) !== 1)
			{
				continue;
			}
			
			$clean = self::cleanPath(str_replace('\\', '/', $match[2]));
			if($clean === null)
			{
				continue;
			}
			
			$kind = match($match[1])
			{
				'?' => 'untracked',
				'M', 'A', 'R' => 'modified',
				default => 'missing',
			};
			$rows[$kind][$clean] = true;
		}
		
		return [
			'untracked' => array_keys($rows['untracked']),
			'modified' => array_keys($rows['modified']),
			'missing' => array_keys($rows['missing']),
		];
	}
	
	/**
	 * svn names an unversioned DIRECTORY once and never looks inside; the
	 * pass does, so a dropped `uploads/shell/` shows its PHP. Files stay as
	 * they are; symlinked directories are not followed; the walk stops at
	 * MAX_EXPANDED files.
	 *
	 * @param list<string> $paths
	 * @return array{paths: list<string>, unreadable: int}
	 */
	public static function expand(
		array $paths,
		string $root,
	): array
	{
		$root = rtrim(str_replace('\\', '/', $root), '/');
		$out = [];
		$unreadable = 0;
		$budget = self::MAX_EXPANDED;
		
		foreach($paths as $path)
		{
			$absolute = $root . '/' . $path;
			if(is_dir($absolute) === false)
			{
				$out[$path] = true;
				continue;
			}
			
			try
			{
				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
					RecursiveIteratorIterator::LEAVES_ONLY,
				);
				foreach($iterator as $file)
				{
					if($budget <= 0)
					{
						break 2;
					}
					if($file->isFile() === false)
					{
						continue;
					}
					$relative = self::cleanPath(substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1));
					if($relative !== null)
					{
						$out[$relative] = true;
						$budget--;
					}
				}
			}
			catch(Throwable)
			{
				// a directory that cannot be read is counted, not guessed at
				$unreadable++;
				$out[$path] = true;
			}
		}
		
		return ['paths' => array_keys($out), 'unreadable' => $unreadable];
	}
	
	/**
	 * What one UNTRACKED path is, and how much attention it deserves — or
	 * null for a file that is only counted (never listed by path)
	 *
	 * @param list<string> $web the web-reachable directories (webDirs)
	 * @return array{detector: string, tier: string}|null
	 */
	public static function classify(
		string $path,
		array $web,
	): ?array
	{
		$name = basename($path);
		$reachable = self::reachable($path, $web);
		
		if(preg_match(self::EXECUTABLE, $name) === 1)
		{
			return ['detector' => self::DETECTOR_FILE, 'tier' => $reachable ? self::TIER_URGENT : self::TIER_HIGH];
		}
		
		if(in_array(strtolower($name), self::CONFIG_NAMES, true))
		{
			return ['detector' => self::DETECTOR_CONFIG, 'tier' => $reachable ? self::TIER_HIGH : self::TIER_INFO];
		}
		
		return null;
	}
	
	/**
	 * How much attention a MODIFIED tracked file deserves: an executable
	 * urgent under a web directory and high elsewhere, a server config file
	 * high there and info elsewhere, a browser script high under a web
	 * directory (the skimmer's shape), anything else info — always listed
	 *
	 * @param list<string> $web the web-reachable directories (webDirs)
	 */
	public static function classifyModified(
		string $path,
		array $web,
	): string
	{
		$name = basename($path);
		$reachable = self::reachable($path, $web);
		
		if(preg_match(self::EXECUTABLE, $name) === 1)
		{
			return $reachable ? self::TIER_URGENT : self::TIER_HIGH;
		}
		
		if(in_array(strtolower($name), self::CONFIG_NAMES, true))
		{
			return $reachable ? self::TIER_HIGH : self::TIER_INFO;
		}
		
		if($reachable && preg_match(self::WEB_SCRIPT, $name) === 1)
		{
			return self::TIER_HIGH;
		}
		
		return self::TIER_INFO;
	}
	
	/**
	 * The findings for what the working copy answered: untracked executables
	 * and config files listed by path with their size and mtime, every other
	 * untracked file folded into one info row per directory (count, bytes,
	 * newest mtime, extension histogram), modified tracked files listed by
	 * path with their tier, missing tracked files listed as info — in tier
	 * order, capped, the remainder counted per tier
	 *
	 * @param array{untracked?: list<string>, modified?: list<string>, missing?: list<string>} $changes paths relative to $root
	 * @param list<string> $web the web-reachable directories (webDirs)
	 * @return array{findings: list<array>, counts: array<string, int>, truncated: array<string, int>,
	 *   dirs: int, executable: int, bytes: int}
	 */
	public static function findings(
		array $changes,
		string $root,
		array $web,
		int $max = self::MAX_FINDINGS,
	): array
	{
		$root = rtrim(str_replace('\\', '/', $root), '/');
		$listed = [self::TIER_URGENT => [], self::TIER_HIGH => [], self::TIER_INFO => []];
		$folded = [];
		$dirs = [];
		$executable = 0;
		$bytes = 0;
		
		foreach($changes['untracked'] ?? [] as $path)
		{
			$dir = self::dirOf($path);
			$dirs[$dir] = true;
			$stat = self::stat($root . '/' . $path);
			$bytes += $stat['size'] ?? 0;
			
			$class = self::classify($path, $web);
			if($class !== null)
			{
				if($class['detector'] === self::DETECTOR_FILE)
				{
					$executable++;
				}
				$listed[$class['tier']][] = self::row($class['detector'], $class['tier'], $path, $stat);
				continue;
			}
			
			$sum = $folded[$dir] ?? ['files' => 0, 'bytes' => 0, 'mtime' => null, 'ext' => []];
			$sum['files']++;
			$sum['bytes'] += $stat['size'] ?? 0;
			if($stat['mtime'] !== null && ($sum['mtime'] === null || $stat['mtime'] > $sum['mtime']))
			{
				$sum['mtime'] = $stat['mtime'];
			}
			$ext = self::extensionOf($path);
			$sum['ext'][$ext] = ($sum['ext'][$ext] ?? 0) + 1;
			$folded[$dir] = $sum;
		}
		
		foreach($folded as $dir => $sum)
		{
			$listed[self::TIER_INFO][] = self::row(self::DETECTOR_DIR, self::TIER_INFO, (string)$dir,
				['size' => $sum['bytes'], 'mtime' => $sum['mtime']], self::describe($sum));
		}
		
		foreach($changes['modified'] ?? [] as $path)
		{
			$dirs[self::dirOf($path)] = true;
			$stat = self::stat($root . '/' . $path);
			$bytes += $stat['size'] ?? 0;
			if(preg_match(self::EXECUTABLE, basename($path)) === 1)
			{
				$executable++;
			}
			$tier = self::classifyModified($path, $web);
			$listed[$tier][] = self::row(self::DETECTOR_MODIFIED, $tier, $path, $stat);
		}
		
		foreach($changes['missing'] ?? [] as $path)
		{
			$dirs[self::dirOf($path)] = true;
			$listed[self::TIER_INFO][] = self::row(self::DETECTOR_MISSING, self::TIER_INFO, $path, ['size' => null, 'mtime' => null]);
		}
		
		$findings = [];
		$counts = [];
		$truncated = [];
		foreach(self::TIERS as $tier)
		{
			$rows = $listed[$tier];
			usort($rows, static fn(array $a, array $b): int => $a['path'] <=> $b['path']);
			$room = max(0, $max - count($findings));
			$counts[$tier] = count($rows);
			$truncated[$tier] = count($rows) - min(count($rows), $room);
			$findings = array_merge($findings, array_slice($rows, 0, $room));
		}
		
		return [
			'findings' => $findings,
			'counts' => $counts,
			'truncated' => $truncated,
			'dirs' => count($dirs),
			'executable' => $executable,
			'bytes' => $bytes,
		];
	}
	
	/**
	 * The sender's name and version as the console's `client` word:
	 * `php-library/<version>` — the installed package's version when composer
	 * knows it, `dev` otherwise, in the console's character set
	 */
	public static function client(): string
	{
		$version = 'dev';
		
		try
		{
			if(class_exists(InstalledVersions::class) && InstalledVersions::isInstalled(self::PACKAGE))
			{
				$version = (string)InstalledVersions::getPrettyVersion(self::PACKAGE);
			}
		}
		catch(Throwable)
		{
			// composer's runtime is not a requirement of the pass
		}
		
		return 'php-library/' . self::word($version);
	}
	
	/**
	 * A version-like word in the console's character set: anything else
	 * becomes a dash, capped at 32 — `dev-release/8.5` reads `dev-release-8.5`
	 */
	public static function word(
		string $value,
	): string
	{
		$clean = trim((string)preg_replace('~[^0-9A-Za-z._-]+~', '-', $value), '-');
		
		return $clean === '' ? 'dev' : substr($clean, 0, 32);
	}
	
	/**
	 * The web-reachable directories, normalized: slashes, no leading or
	 * trailing slash, '' and '.' both meaning the root itself
	 *
	 * @param list<string> $web
	 * @return list<string>
	 */
	public static function webDirs(
		array $web,
	): array
	{
		$dirs = [];
		foreach($web as $dir)
		{
			$dir = trim(str_replace('\\', '/', (string)$dir), '/');
			$dirs[$dir === '' ? '.' : $dir] = true;
		}
		
		return array_keys($dirs);
	}
	
	/**
	 * Runs one command without a shell and answers its stdout on a zero exit
	 * — null when the command could not start, exited non-zero, or ran past
	 * the budget (then it is terminated, not waited for). No pipes: stdout
	 * goes to a temp file and stderr to the null device, and the parent only
	 * polls proc_get_status — a pipe would need a non-blocking read to honour
	 * the budget, and Windows pipes block regardless of the blocking flag.
	 *
	 * @param list<string> $command
	 */
	public static function run(
		array $command,
		string $cwd,
		int $timeoutMs,
	): ?string
	{
		if(function_exists('proc_open') === false || is_dir($cwd) === false)
		{
			return null;
		}
		
		try
		{
			$stdout = tempnam(sys_get_temp_dir(), 'ovos-untracked-');
		}
		catch(Throwable)
		{
			return null;
		}
		if($stdout === false)
		{
			return null;
		}
		
		$pipes = [];
		$code = -1;
		$failed = false;
		$deadline = hrtime(true) + $timeoutMs * 1_000_000;
		
		try
		{
			$process = proc_open(
				$command,
				[1 => ['file', $stdout, 'w'], 2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']],
				$pipes,
				$cwd,
				null,
				['bypass_shell' => true],
			);
			if(is_resource($process) === false)
			{
				$failed = true;
			}
			else
			{
				while(true)
				{
					$status = proc_get_status($process);
					if(($status['running'] ?? false) === false)
					{
						// the first not-running answer carries the exit code; proc_close will not
						$code = (int)($status['exitcode'] ?? -1);
						break;
					}
					
					if(hrtime(true) > $deadline)
					{
						$failed = true;
						proc_terminate($process);
						break;
					}
					
					usleep(20_000);
				}
				proc_close($process);
			}
			
			$output = $failed || $code !== 0 ? null : file_get_contents($stdout);
		}
		catch(Throwable)
		{
			$output = null;
		}
		finally
		{
			try
			{
				if(is_file($stdout))
				{
					unlink($stdout);
				}
			}
			catch(Throwable)
			{
				// a temp file left behind is not worth a warning on the run that leaves it
			}
		}
		
		return $output === false ? null : $output;
	}
	
	/**
	 * One finding in the console's shape
	 *
	 * @param array{size: ?int, mtime: ?int} $stat
	 */
	protected static function row(
		string $detector,
		string $tier,
		string $path,
		array $stat,
		string $detail = '',
	): array
	{
		return [
			'detector' => $detector,
			'tier' => $tier,
			'area' => self::AREA,
			'path' => $path,
			'size' => $stat['size'],
			'mtime' => $stat['mtime'],
			'detail' => $detail,
		];
	}
	
	/**
	 * A relative path the console will take: trimmed, no `./` prefix, not
	 * absolute, no control characters, no `..` segment — null otherwise
	 */
	protected static function cleanPath(
		string $path,
	): ?string
	{
		$path = trim($path);
		while(str_starts_with($path, './'))
		{
			$path = substr($path, 2);
		}
		
		if($path === '' || $path === '.' || str_starts_with($path, '/')
			|| preg_match('~[\x00-\x1f\x7f]~', $path) === 1
			|| preg_match('~^[A-Za-z]:~', $path) === 1)
		{
			return null;
		}
		
		if($path === '..' || str_starts_with($path, '../') || str_contains($path, '/../') || str_ends_with($path, '/..'))
		{
			return null;
		}
		
		return $path;
	}
	
	/**
	 * Whether a path sits under one of the web-reachable directories ('.'
	 * makes the whole working copy reachable)
	 *
	 * @param list<string> $web
	 */
	protected static function reachable(
		string $path,
		array $web,
	): bool
	{
		foreach($web as $dir)
		{
			if($dir === '.' || $path === $dir || str_starts_with($path, $dir . '/'))
			{
				return true;
			}
		}
		
		return false;
	}
	
	/**
	 * The VCS directory's name when it sits inside a web-reachable directory
	 * (the root itself being the docroot is the common case) — the console
	 * prints it as ".git in the document root"
	 *
	 * @param list<string> $web
	 * @return list<string>
	 */
	protected static function exposed(
		string $root,
		string $vcs,
		array $web,
	): array
	{
		foreach($web as $dir)
		{
			$base = $dir === '.' ? $root : $root . '/' . $dir;
			if(self::detect($base) === $vcs)
			{
				return ['.' . $vcs];
			}
		}
		
		return [];
	}
	
	/**
	 * @return array{size: ?int, mtime: ?int}
	 */
	protected static function stat(
		string $file,
	): array
	{
		try
		{
			if(is_file($file))
			{
				$size = filesize($file);
				$mtime = filemtime($file);
				
				return [
					'size' => $size === false ? null : (int)$size,
					'mtime' => $mtime === false ? null : (int)$mtime,
				];
			}
		}
		catch(Throwable)
		{
			// a file that vanished between the listing and the stat has no numbers
		}
		
		return ['size' => null, 'mtime' => null];
	}
	
	protected static function dirOf(
		string $path,
	): string
	{
		$slash = strrpos($path, '/');
		
		return $slash === false ? '.' : substr($path, 0, $slash);
	}
	
	/**
	 * The histogram's key for a file: its lowercase extension when it looks
	 * like one, `none` without, `other` for a strange one
	 */
	protected static function extensionOf(
		string $path,
	): string
	{
		$name = basename($path);
		$dot = strrpos($name, '.');
		if($dot === false || $dot === 0 || $dot === strlen($name) - 1)
		{
			return 'none';
		}
		
		$ext = strtolower(substr($name, $dot + 1));
		
		return preg_match('~^[a-z0-9]{1,12}$~', $ext) === 1 ? '.' . $ext : 'other';
	}
	
	/**
	 * "3 files · .pdf ×2 · .log ×1" — the directory row's detail, most
	 * frequent extension first, capped like the console's column
	 *
	 * @param array{files: int, ext: array<string, int>} $sum
	 */
	protected static function describe(
		array $sum,
	): string
	{
		$ext = $sum['ext'];
		arsort($ext);
		$parts = [$sum['files'] . ($sum['files'] === 1 ? ' file' : ' files')];
		foreach($ext as $name => $count)
		{
			$parts[] = $name . ' ×' . $count;
		}
		
		$detail = implode(' · ', $parts);
		
		return mb_strlen($detail) > self::MAX_DETAIL ? mb_substr($detail, 0, self::MAX_DETAIL - 1) . '…' : $detail;
	}
}
