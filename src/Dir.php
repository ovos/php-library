<?php
declare(strict_types=1);

namespace Ovos;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function count;
use function file_exists;
use function func_get_args;
use function is_dir;
use function is_readable;
use function ksort;
use function ltrim;
use function mkdir;
use function preg_match;
use function rmdir;
use function rtrim;
use function scandir;
use function sort;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;
use function umask;
use function unlink;

/**
 * Dir
 * Recursive directory creation and removal
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Dir
{
	// Filters
	public const int FILTER_NONE = 0;
	public const int FILTER_DIRECTORIES = 1;
	public const int FILTER_FILES = 2;
	
	/**
	 * Creates a directory structure
	 */
	public static function create(
		string $path,
		int $mode = 0777, // (octal)
		bool $normalize = true,
	): bool
	{
		if($normalize)
		{
			$path = self::normalize($path);
		}
		
		if(empty($path) || is_dir($path))
		{
			return true;
		}
		
		$nextDir = substr($path, 0, strrpos($path, DIRECTORY_SEPARATOR));
		if(self::create($nextDir, $mode))
		{
			if(file_exists($path) === false)
			{
				$umask = umask(0);
				$result = mkdir($path, $mode);
				umask($umask);
				return $result;
			}
			
			return true;
		}
		
		return false;
	}
	
	/**
	 * Normalizes a path to the PLATFORM separator: every / and \ becomes
	 * DIRECTORY_SEPARATOR, a trailing separator is dropped, and with
	 * $relative also a leading one. For LOCAL filesystem work only — code
	 * comparing paths across machines (a reported path against a git tree)
	 * must normalize to a fixed '/' itself, or the answer depends on which
	 * OS happens to run it.
	 */
	public static function normalize(
		string $path,
		bool $relative = false,
	): string
	{
		$path = str_replace(['/', '\\'], [DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR], $path);
		$path = rtrim($path, DIRECTORY_SEPARATOR);
		if($relative)
		{
			$path = ltrim($path, DIRECTORY_SEPARATOR);
		}
		
		return $path;
	}
	
	/**
	 * @deprecated the PHP 4 era name of normalize() — use that; this alias
	 * keeps any external caller working and will go with the next major
	 */
	public static function preProcess(
		string $path,
		bool $relative = false,
	): string
	{
		return self::normalize($path, $relative);
	}
	
	/**
	 * Normalizes to the FIXED '/' separator — normalize()'s sibling for
	 * paths that cross machines (wire payloads, git trees, archive entries),
	 * where the answer must not depend on which OS happens to run the code.
	 * Whitespace padding is trimmed (wire data), a trailing separator is
	 * dropped, and with $relative a leading one too.
	 */
	public static function posix(
		string $path,
		bool $relative = false,
	): string
	{
		$path = rtrim(str_replace('\\', '/', trim($path)), '/');
		if($relative)
		{
			$path = ltrim($path, '/');
		}
		
		return $path;
	}
	
	/**
	 * The relative form of $path under $base, both taken as POSIX — or null
	 * when no honest answer exists: an empty side, $path === $base, or $path
	 * outside $base. Containment is checked on a DIRECTORY boundary, so
	 * /srv/app2/x is never "under" /srv/app.
	 */
	public static function relative(
		string $path,
		string $base,
	): ?string
	{
		$path = self::posix($path);
		$base = self::posix($base);
		
		if($base === '' || str_starts_with($path, $base . '/') === false)
		{
			return null;
		}
		
		$relative = ltrim(substr($path, strlen($base) + 1), '/');
		
		return $relative === '' ? null : $relative;
	}
	
	/**
	 * Whether $path is $prefix itself or lives under it — the
	 * boundary-anchored prefix test an allowlist needs, where 'vendor'
	 * (or 'vendor/') must match vendor/lib.php but never vendor.php.
	 * Both sides are taken as RELATIVE POSIX paths.
	 */
	public static function under(
		string $path,
		string $prefix,
	): bool
	{
		$path = self::posix($path, true);
		$prefix = self::posix($prefix, true);
		
		return $prefix !== ''
			&& ($path === $prefix || str_starts_with($path, $prefix . '/'));
	}
	
	/**
	 * Whether $path is absolute on ANY filesystem this code may meet —
	 * POSIX (/etc/…), a Windows drive (C:\… or C:/…) or UNC (\\host\share)
	 * — so a configured path is either taken as it is or resolved against
	 * the application's base directory, whatever OS resolves it. A relative
	 * path, '' and a bare drive letter without a separator (C:file) are not.
	 */
	public static function isAbsolute(
		string $path,
	): bool
	{
		return str_starts_with($path, '/')
			|| str_starts_with($path, '\\\\')
			|| preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1;
	}
	
	/**
	 * Remove the directory with all its contents
	 */
	public static function remove(
		string $path,
		bool $remove = true,
		?string $match = null, // a regular expression
	): void
	{
		if(is_dir($path) === false)
		{
			return;
		}
		
		$directoryIterator = new RecursiveDirectoryIterator(
			$path,
			FilesystemIterator::SKIP_DOTS,
		);
		/**
		 * @var RecursiveDirectoryIterator $iterator
		 */
		foreach(
			$iterator = new RecursiveIteratorIterator(
				$directoryIterator,
				RecursiveIteratorIterator::CHILD_FIRST,
			) as $file
		)
		{
			/**
			 * @var SplFileInfo $file
			 */
			if($match !== null && preg_match(
				$match,
				$file->getFilename(),
			) === 0)
			{
				continue;
			}
			
			if(str_contains($file->getPathname(), '.svn'))
			{
				continue;
			}
			
			if($file->isFile())
			{
				unlink($file->getPathname());
			}
			else
			{
				rmdir($file->getPathname());
			}
		}
		
		if($remove)
		{
			rmdir($path);
		}
	}
	
	/**
	 * Empty the directory or recursively remove files and directories matching regular expression
	 */
	public static function clear(
		string $path,
		?string $match = null, // regular expression
	): void
	{
		self::remove($path, false, $match);
	}
	
	/**
	 * Remove the path of directories if they are empty
	 */
	public static function removeEmpty(
		string $pathToKeep, // absolute path
		string $path, // relative path
	): void
	{
		$pathToKeep = self::normalize($pathToKeep);
		$path = self::normalize($path, true);
		
		if(!empty($path) && is_dir($pathToKeep))
		{
			$pathToRemove = $pathToKeep . DIRECTORY_SEPARATOR . $path;
			
			if(is_dir($pathToRemove))
			{
				if(self::isEmpty($pathToRemove))
				{
					rmdir($pathToRemove);
				}
				else
				{
					return;
				}
			}
			
			if(str_contains($path, DIRECTORY_SEPARATOR))
			{
				$pathUp = substr($path,
					0,
					strrpos($path, DIRECTORY_SEPARATOR),
				);
				self::removeEmpty($pathToKeep, $pathUp);
			}
		}
	}
	
	/**
	 * Moves contents of one directory to another recursively
	 * without removing the target directory's contents
	 */
	public static function moveFiles(
		string $pathFrom,
		string $pathTo,
	): void
	{
		$pathFrom = self::normalize($pathFrom);
		$pathTo = self::normalize($pathTo);
		
		if(is_dir($pathFrom) && is_dir($pathTo))
		{
			$directoryIterator = new RecursiveDirectoryIterator(
				$pathFrom,
				FilesystemIterator::SKIP_DOTS,
			);
			/**
			 * @var RecursiveDirectoryIterator $iterator
			 */
			foreach(
				$iterator = new RecursiveIteratorIterator(
					$directoryIterator,
					RecursiveIteratorIterator::CHILD_FIRST,
				) as $file
			)
			{
				/**
				 * @var SplFileInfo $file
				 */
				if($file->isFile())
				{
					self::create($pathTo
						. DIRECTORY_SEPARATOR . $iterator->getSubPath());
					rename((string)$file, $pathTo
						. DIRECTORY_SEPARATOR . $iterator->getSubPathName());
				}
				else
				{
					self::remove($file->getPathname());
				}
			}
		}
	}
	
	/**
	 * Copy contents of one directory to another recursively
	 * without removing the target directory's contents
	 */
	public static function copyFiles(
		string $pathFrom,
		string $pathTo,
		bool $overwrite = false,
		?callable $filenameCallback = null,
		?callable $callback = null,
	): void
	{
		$pathFrom = self::normalize($pathFrom);
		$pathTo = self::normalize($pathTo);
		
		if(is_dir($pathFrom) && is_dir($pathTo))
		{
			$directoryIterator = new RecursiveDirectoryIterator(
				$pathFrom, 
				FilesystemIterator::SKIP_DOTS,
			);
			/**
			 * @var RecursiveDirectoryIterator $iterator
			 */
			foreach(
				$iterator = new RecursiveIteratorIterator(
					$directoryIterator, 
					RecursiveIteratorIterator::SELF_FIRST,
				) as $file
			)
			{
				$filename = $filenameCallback
					? $filenameCallback($file)
					: $file->getFilename();
				if($filename === null)
				{
					continue;
				}
				
				/**
				 * @var SplFileInfo $file
				 */
				if($file->isDir())
				{
					self::create($pathTo
						. DIRECTORY_SEPARATOR . $iterator->getSubPath()
						. DIRECTORY_SEPARATOR . $filename
					);
				}
				else
				{
					$destination = $pathTo
						. DIRECTORY_SEPARATOR . $iterator->getSubPath()
						. DIRECTORY_SEPARATOR . $filename;
					
					if($overwrite === true
						|| file_exists($destination) === false
					)
					{
						if(copy((string)$file, $destination) // on successful operation
							&& $callback) // if callback exists
						{
							$callback($file, $destination);
						}
					}
				}
			}
		}
	}
	
	/**
	 * Check if the directory is empty
	 */
	public static function isEmpty(
		string $path,
	): ?bool
	{
		if(is_readable($path) === false)
		{
			return null;
		}
		return (count(scandir($path, SCANDIR_SORT_NONE)) === 2); // if only array('..', '.');
	}
	
	public static function getDirectoriesTree(
		string $path,
		bool $skipHidden = true,
		?callable $skipCallback = null,
		?callable $filenameCallback = null, // callback function for filename processing
		int $filter = self::FILTER_DIRECTORIES,
	): array
	{
		return self::getTree(...func_get_args());
	}
	
	public static function getTree(
		string $path,
		bool $skipHidden = true,
		?callable $skipCallback = null,
		?callable $filenameCallback = null, // callback function for filename processing
		int $filter = self::FILTER_NONE,
	): array
	{
		$dirs = [];
		$files = [];
		
		foreach(new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $file)
		{
			// hidden files, eg. ".gitkeep"
			if($skipHidden && $file->getFilename()[0] === '.')
			{
				continue;
			}
			
			/**
			 * @var SplFileInfo $file
			 */
			if($filter && $filter === self::FILTER_DIRECTORIES
				&& $file->isFile())
			{
				continue;
			}
			
			if($skipCallback && $skipCallback($file) === true)
			{
				continue;
			}
			
			$filename = $filenameCallback
				? $filenameCallback($file)
				: $file->getFilename();
			if($filename === null)
			{
				continue;
			}
			
			if($file->isFile())
			{
				$files[] = $filename;
				
				continue;
			}
			
			// dir
			$dirs[$filename] = self::getTree(
				$file->getPathname(),
				$skipHidden,
				$skipCallback,
				$filenameCallback,
				$filter
			);
		}
		
		// the order depends on filesystem
		// sort names of files
		sort($files, SORT_NATURAL);
		// sort names of directories
		ksort($dirs, SORT_NATURAL);
		
		return $dirs + $files;
	}
	
	public static function getFiles(
		string $path,
		bool $skipHidden = true,
		?callable $skipCallback = null,
		int $filter = self::FILTER_NONE,
	): array
	{
		$files = [];
		
		$directoryIterator = new RecursiveDirectoryIterator(
			$path,
			FilesystemIterator::SKIP_DOTS,
		);
		/**
		 * @var RecursiveDirectoryIterator $iterator
		 */
		foreach(
			$iterator = new RecursiveIteratorIterator(
				$directoryIterator, 
				RecursiveIteratorIterator::CHILD_FIRST,
			) as $file
		)
		{
			// hidden files, eg. ".gitkeep"
			if($skipHidden && $file->getFilename()[0] === '.')
			{
				continue;
			}
			
			/**
			 * @var SplFileInfo $file
			 */
			if($filter && $filter === self::FILTER_FILES
				&& $file->isDir())
			{
				continue;
			}
			
			if($skipCallback && $skipCallback($file) === true)
			{
				continue;
			}
			
			$files[$iterator->getSubPathname()] = $file;
		}
		
		return $files;
	}
}
