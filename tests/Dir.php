<?php
declare(strict_types=1);

namespace Tests;

use Ovos\Test;
use Ovos\Dir as BaseDir;

/**
 * Dir
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Dir extends Test
{
	protected string $dir = __DIR__
		. DIRECTORY_SEPARATOR . 'Dir'
		. DIRECTORY_SEPARATOR . 'files'
		. DIRECTORY_SEPARATOR;
	
	public function normalizeUnifiesSeparatorsAndTrims(): bool
	{
		$s = DIRECTORY_SEPARATOR;
		
		return BaseDir::normalize('a/b\\c/') === 'a' . $s . 'b' . $s . 'c'
			&& BaseDir::normalize('/a/b/', true) === 'a' . $s . 'b'
			&& BaseDir::normalize('/a/b/') === $s . 'a' . $s . 'b'
			&& BaseDir::normalize('') === '';
	}
	
	public function preProcessAliasesNormalize(): bool
	{
		return BaseDir::preProcess('/x\\y/', true) === BaseDir::normalize('/x\\y/', true)
			&& BaseDir::preProcess('/x/y/') === BaseDir::normalize('/x/y/');
	}
	
	/**
	 * posix() is normalize()'s fixed-'/' sibling for paths that cross
	 * machines: a Windows sender's D:\app\File.php and a Linux one's
	 * /srv/app/File.php must land on one vocabulary whatever OS runs this
	 * code — so the separator is pinned, never DIRECTORY_SEPARATOR
	 */
	public function posixPinsForwardSlashesWhateverTheOs(): bool
	{
		return BaseDir::posix('D:\\app\\src\\Cart.php') === 'D:/app/src/Cart.php'
			&& BaseDir::posix('/srv/app/') === '/srv/app'
			&& BaseDir::posix(' /srv/app/x.php ') === '/srv/app/x.php'
			&& BaseDir::posix('/a/b/', true) === 'a/b'
			&& BaseDir::posix('') === '';
	}
	
	/**
	 * relative() answers null for every "not honestly under" — including
	 * the /srv/app2-under-/srv/app near-miss, which is a prefix of the
	 * STRING but not of the PATH; a directory boundary decides
	 */
	public function relativeChecksContainmentOnADirectoryBoundary(): bool
	{
		return BaseDir::relative('/srv/app/src/Cart.php', '/srv/app') === 'src/Cart.php'
			&& BaseDir::relative('D:\\app\\src\\Cart.php', 'D:/app/') === 'src/Cart.php'
			&& BaseDir::relative('/srv/app2/index.php', '/srv/app') === null
			&& BaseDir::relative('/srv/app', '/srv/app') === null
			&& BaseDir::relative('/elsewhere/x.php', '/srv/app') === null
			&& BaseDir::relative('/srv/app/x.php', '') === null
			&& BaseDir::relative('', '/srv/app') === null;
	}
	
	/**
	 * under() is the allowlist question: at or below the prefix, anchored
	 * on the boundary — 'vendor' must cover vendor/lib.php and vendor
	 * itself, and must never cover vendor.php
	 */
	public function underAnchorsThePrefixOnTheBoundary(): bool
	{
		return BaseDir::under('vendor/ovos/lib.php', 'vendor/')
			&& BaseDir::under('vendor', 'vendor')
			&& BaseDir::under('var/generated/Proxy.php', '/var\\generated')
			&& BaseDir::under('vendor.php', 'vendor') === false
			&& BaseDir::under('src/vendor/x.php', 'vendor') === false
			&& BaseDir::under('anything', '') === false;
	}
	
	/**
	 * isAbsolute() knows the three roots a configured path may start from
	 * — POSIX, a Windows drive with either separator, UNC — and nothing
	 * else: a relative path, an empty one, a bare drive without separator
	 * and a lone backslash are resolved against the base directory instead
	 */
	public function isAbsoluteKnowsPosixDriveAndUnc(): bool
	{
		return BaseDir::isAbsolute('/etc/ssl/key.pem')
			&& BaseDir::isAbsolute('/')
			&& BaseDir::isAbsolute('C:\\keys\\app.pem')
			&& BaseDir::isAbsolute('d:/keys/app.pem')
			&& BaseDir::isAbsolute('\\\\fileserver\\keys\\app.pem')
			&& BaseDir::isAbsolute('secrets/app.pem') === false
			&& BaseDir::isAbsolute('./secrets/app.pem') === false
			&& BaseDir::isAbsolute('C:secrets') === false
			&& BaseDir::isAbsolute('\\secrets') === false
			&& BaseDir::isAbsolute('') === false;
	}
	
	public function copyFiles(): bool
	{
		$copy = $this->dir . 'copy';
		$copy2 = $this->dir . 'copy2';
		BaseDir::create($copy2);
		BaseDir::copyFiles($copy, $copy2);
		
		$copyFiles = array_keys(BaseDir::getFiles($copy));
		$copy2Files = array_keys(BaseDir::getFiles($copy2));
		BaseDir::remove($copy2);
		
		return $copyFiles === $copy2Files;
	}
	
	public function moveFiles(): bool
	{
		$copy = $this->dir . 'copy';
		$copy2 = $this->dir . 'copy2';
		$move = $this->dir . 'move';
		BaseDir::create($copy2);
		BaseDir::copyFiles($copy, $copy2);
		BaseDir::create($move);
		BaseDir::moveFiles($copy2, $move);
		BaseDir::remove($copy2);
		
		$copyFiles = array_keys(BaseDir::getFiles($copy));
		$moveFiles = array_keys(BaseDir::getFiles($move));
		BaseDir::remove($move);
		
		return $copyFiles === $moveFiles;
	}
	
	public function getTree(): bool
	{
		$copy = $this->dir . 'copy';
		$tree = [
			'dir1' => [
				'dir1' => [
					0 => 'file1.txt',
				],
				0 => 'file1.txt',
				1 => 'file2.txt',
			],
			'dir2' => [
				0 => 'file1.txt',
			],
			0 => 'file1.txt',
			1 => 'file2.txt',
		];
		
		$result = $tree === BaseDir::getTree($copy);
		
		// DEBUG
		if($result === false)
		{
			echo 'DEBUG ', __METHOD__, PHP_EOL;
			var_dump(BaseDir::getTree($copy));
		}
		
		return $result;
	}
	
	public function getFiles(): bool
	{
		$copy = $this->dir . 'copy';
		
		return count(BaseDir::getFiles($copy)) === 9;
	}
	
	public function getFilesFilterFiles(): bool
	{
		$copy = $this->dir . 'copy';
		
		return count(BaseDir::getFiles($copy, filter: BaseDir::FILTER_FILES)) === 6;
	}
}
