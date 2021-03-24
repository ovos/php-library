<?php
declare(strict_types=1);

namespace Ovos;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use function strlen;
use function count;
use function krsort;
use function substr;
use function file_exists;
use function umask;
use function mkdir;
use function str_replace;
use function str_contains;
use function unlink;
use function rtrim;
use function ltrim;
use function preg_match;
use function rmdir;
use function is_readable;
use function scandir;
use function is_dir;

/**
 * Dir
 * Recursive directory creation and removal
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Dir
{
	/**
	 * Creates a directory structure
	 *
	 * @param string $path
	 * @param integer (octal) $mode
	 * @param bool $preProcess
	 *
	 * @return bool
	 */
	public static function create(string $path, int $mode = 0777, bool $preProcess = true): bool
	{
		if($preProcess)
		{
			$path = self::preProcess($path);
		}

		if(is_dir($path) || empty($path))
		{
			return true;
		}

		$nextDir = substr($path, 0, strrpos($path, DIRECTORY_SEPARATOR));
		if(self::create($nextDir, $mode))
		{
			if(!file_exists($path))
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
	 * Pre-processes a path or relative path (second param)
	 * Removes directory separator from the end and replaces all separators with consistent ones
	 * Set $relative to true to remove directory separator also from the start of the string
	 *
	 * @param string $path
	 * @param bool $relative
	 *
	 * @return string
	 */
	public static function preProcess(string $path, bool $relative = false): string
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
	 * Remove the directory with all it's contents
	 *
	 * @param string $path
	 * @param bool $remove
	 * @param null|string $match (regular expression)
	 *
	 * @return void
	 */
	public static function remove(string $path, bool $remove = true, null|string $match = null): void
	{
		if(is_dir($path))
		{
			$iterator = new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS);
			foreach(new RecursiveIteratorIterator($iterator, RecursiveIteratorIterator::CHILD_FIRST) as $file)
			{
				/**
				 * @var SplFileInfo $file
				 */
				if($match !== null && !preg_match($match, $file->getFilename()))
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

			if($remove) rmdir($path);
		}
	}

	/**
	 * Empty the directory or remove recursively files and directories matching regular expression
	 *
	 * @param string $path
	 * @param null|string $match (regular expression)
	 *
	 * @return void
	 */
	public static function clear(string $path, null|string $match = null): void
	{
		self::remove($path, false, $match);
	}

	/**
	 * Remove path of directories if they are empty
	 *
	 * @param string $pathToKeep absolute path
	 * @param string $path relative path
	 *
	 * @return void
	 */
	public static function removeEmpty(string $pathToKeep, string $path): void
	{
		$pathToKeep = self::preProcess($pathToKeep);
		$path = self::preProcess($path, true);

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
				$pathUp = substr($path, 0, strrpos($path, DIRECTORY_SEPARATOR));
				self::removeEmpty($pathToKeep, $pathUp);
			}
		}
	}

	/**
	 * Moves contents of one directory to another recursively without removing target directory's contents
	 *
	 * @param string $pathFrom
	 * @param string $pathTo
	 *
	 * @return void
	 */
	public static function moveFiles(string $pathFrom, string $pathTo): void
	{
		$pathFrom = self::preProcess($pathFrom);
		$pathTo = self::preProcess($pathTo);

		if(is_dir($pathFrom) && is_dir($pathTo))
		{
			$iterator = new RecursiveDirectoryIterator($pathFrom, FilesystemIterator::SKIP_DOTS);
			foreach(new RecursiveIteratorIterator($iterator, RecursiveIteratorIterator::CHILD_FIRST) as $file)
			{
				/**
				 * @var SplFileInfo $file
				 */
				if($file->isFile())
				{
					$source = $file->getPathname();
					$targetPath = $file->getPath();
					
					if(str_starts_with($targetPath, $pathFrom) === 0)
					{
						$targetPath = substr($targetPath, strlen($pathFrom));
					}

					self::create($pathTo . $targetPath . DIRECTORY_SEPARATOR);
					rename($source, $pathTo . $targetPath . DIRECTORY_SEPARATOR . $file->getFilename());
				}
				else
				{
					self::remove($file->getPathname());
				}
			}
		}
	}

	/**
	 * Check if the directory is empty
	 *
	 * @param string $path
	 *
	 * @return bool|null
	 */
	public static function isEmpty(string $path): ?bool
	{
		if(!is_readable($path))
		{
			return null;
		}
		return (count(scandir($path, SCANDIR_SORT_NONE)) === 2); // if only array('..', '.');
	}

	/**
	 * @param string $path
	 *
	 * @return array
	 */
	public static function getTree(string $path): array
	{
		$dirs = [];

		foreach(new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $file)
		{
			/**
			 * @var SplFileInfo $file
			 */
			if($file->isDir() === false)
			{
				continue;
			}

			$dirs[$file->getFilename()] = self::getTree($file->getPathname());
		}
		ksort($dirs, SORT_NATURAL);

		return $dirs;
	}

	/**
	 * @param string $path
	 * @param null|callable $callback Callback function for basename processing
	 *
	 * @return array
	 */
	public static function getFilesTree(string $path, null|callable $callback = null): array
	{
		$files = [];

		foreach(new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $file)
		{
			// hidden files, eg. ".gitkeep"
			if($file->getBasename()[0] === '.')
			{
				continue;
			}

			$basename = $callback ? $callback($file) : $file->getBasename();
			if($basename === null)
			{
				continue;
			}

			/**
			 * @var SplFileInfo $file
			 */
			if($file->isDir() === false)
			{
				$files[] = $basename;

				continue;
			}
			
			// dir
			$files[$basename] = self::getFilesTree($file->getPathname(), $callback);
		}
		// directories first
		krsort($files, SORT_NATURAL);
		
		return $files;
	}

	/**
	 * @param string $path
	 * @param null|callable $callback Callback function for basename processing
	 *
	 * @return array
	 */
	public static function getFiles(string $path, null|callable $callback = null): array
	{
		$files = [];

		$iterator = new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS);
		foreach(new RecursiveIteratorIterator($iterator, RecursiveIteratorIterator::CHILD_FIRST) as $file)
		{
			/**
			* @var SplFileInfo $file
			*/
			if($file->isDir())
			{
				continue;
			}
			
			// hidden files, eg. ".gitkeep"
			if($file->getBasename()[0] === '.')
			{
				continue;
			}
			
			$basename = $callback ? $callback($file) : $file->getBasename();
			if($basename === null)
			{
				continue;
			}
			
			$files[$basename] = $file;
		}
		
		return $files;
	}
}
