<?php
declare(strict_types=1);

namespace Ovos;

use DirectoryIterator;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

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
	 * @param string $dir
	 * @param integer (octal) $mode
	 * @param bool $preProcess
	 *
	 * @return bool
	 */
	public static function create(string $dir, int $mode = 0777, bool $preProcess = true): bool
	{
		if($preProcess)
		{
			$dir = self::preProcess($dir);
		}

		if(is_dir($dir) || empty($dir))
		{
			return true;
		}

		$nextDir = substr($dir, 0, strrpos($dir, DIRECTORY_SEPARATOR));
		if(self::create($nextDir, $mode))
		{
			if(!file_exists($dir))
			{
				$umask = umask(0);
				$result = mkdir($dir, $mode);
				umask($umask);
				return $result;
			}

			return true;
		}

		return false;
	}

	/**
	 * Pre-processes a directory name or path name (second param)
	 * Removes directory separator from the end and replaces all separators with consistent ones
	 * Set $relative to true to remove directory separator also from the start of the string
	 *
	 * @param string $dir
	 * @param bool $relative
	 *
	 * @return string
	 */
	public static function preProcess(string $dir, bool $relative = false): string
	{
		$dir = str_replace(['/', '\\'], [DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR], $dir);
		$dir = rtrim($dir, DIRECTORY_SEPARATOR);
		if($relative)
		{
			$dir = ltrim($dir, DIRECTORY_SEPARATOR);
		}

		return $dir;
	}

	/**
	 * Remove the directory with all it's contents
	 *
	 * @param string $dir
	 * @param bool $removeDir
	 * @param string $match (regular expression)
	 *
	 * @return void
	 */
	public static function remove(string $dir, bool $removeDir = true, string $match = null): void
	{
		if(is_dir($dir))
		{
			$iterator = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
			foreach(new RecursiveIteratorIterator($iterator, RecursiveIteratorIterator::CHILD_FIRST) as $file)
			{
				if($match !== null && !preg_match($match, $file->getFilename()))
				{
					continue;
				}

				if(strpos($file->getPathname(), '.svn') !== false)
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

			if($removeDir) rmdir($dir);
		}
	}

	/**
	 * Empty the directory or remove recursively files and directories matching regular expression
	 *
	 * @param string $dir
	 * @param string $match (regular expression)
	 *
	 * @return void
	 */
	public static function clear(string $dir, string $match = null): void
	{
		self::remove($dir, false, $match);
	}

	/**
	 * Remove path of directories if they are empty
	 *
	 * @param string $pathToKeep absolute path
	 * @param string $path relative path
	 *
	 * @return void
	 */
	public static function removeEmptyPath(string $pathToKeep, string $path): void
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

			if(strpos($path, DIRECTORY_SEPARATOR) !== false)
			{
				$pathUp = substr($path, 0, strrpos($path, DIRECTORY_SEPARATOR));
				self::removeEmptyPath($pathToKeep, $pathUp);
			}
		}
	}

	/**
	 * Moves contents of one directory to another recursively without removing target directory's contents
	 *
	 * @param string $dirFrom
	 * @param string $dirTo
	 *
	 * @return void
	 */
	public static function moveFiles(string $dirFrom, string $dirTo): void
	{
		$dirFrom = self::preProcess($dirFrom);
		$dirTo = self::preProcess($dirTo);

		if(is_dir($dirFrom) && is_dir($dirTo))
		{
			$iterator = new RecursiveDirectoryIterator($dirFrom, FilesystemIterator::SKIP_DOTS);
			foreach(new RecursiveIteratorIterator($iterator, RecursiveIteratorIterator::CHILD_FIRST) as $file)
			{
				if($file->isFile())
				{
					$source = $file->getPathname();
					$targetPath = $file->getPath();
					
					if(strpos($targetPath, $dirFrom) === 0)
					{
						$targetPath = substr($targetPath, \strlen($dirFrom));
					}

					self::create($dirTo . $targetPath . DIRECTORY_SEPARATOR);
					rename($source, $dirTo . $targetPath . DIRECTORY_SEPARATOR . $file->getFilename());
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
	 * @param string $dir
	 *
	 * @return bool|null
	 */
	public static function isEmpty(string $dir): ?bool
	{
		if(!is_readable($dir))
		{
			return null;
		}
		return (\count(scandir($dir, SCANDIR_SORT_NONE)) === 2); // if only array('..', '.');
	}

	/**
	 * @param string $dir
	 *
	 * @return array
	 */
	public static function getTree(string $dir): array
	{
		$dirs = [];

		foreach(new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS) as $file)
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

		return $dirs;
	}

	/**
	 * @param string $dir
	 * @param callable $callback Callback function for basename processing
	 *
	 * @return array
	 */
	public static function getFiles(string $dir, callable $callback = null): array
	{
		$files = [];

		foreach(new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS) as $file)
		{
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

			$files[$basename] = self::getFiles($file->getPathname(), $callback);
		}
		// directories first
		krsort($files, SORT_NATURAL);
		
		return $files;
	}
}
