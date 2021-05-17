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
use function func_get_args;

/**
 * Dir
 * Recursive directory creation and removal
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Dir
{
	/**#@+
	 * Filter constants
	 */
	public const FILTER_NONE = 0;
	public const FILTER_DIRECTORIES = 1;
	public const FILTER_FILES = 2;
	/**#@-*/
	
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
	 * @param ?string $match (regular expression)
	 *
	 * @return void
	 */
	public static function remove(string $path, bool $remove = true, ?string $match = null): void
	{
		if(is_dir($path))
		{
			$directoryIterator = new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS);
			/**
			 * @var RecursiveDirectoryIterator $iterator
			 */
			foreach($iterator = new RecursiveIteratorIterator($directoryIterator, RecursiveIteratorIterator::CHILD_FIRST) as $file)
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
	 * @param ?string $match (regular expression)
	 *
	 * @return void
	 */
	public static function clear(string $path, ?string $match = null): void
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
			$directoryIterator = new RecursiveDirectoryIterator($pathFrom, FilesystemIterator::SKIP_DOTS);
			/**
			 * @var RecursiveDirectoryIterator $iterator
			 */
			foreach($iterator = new RecursiveIteratorIterator($directoryIterator, RecursiveIteratorIterator::CHILD_FIRST) as $file)
			{
				/**
				 * @var SplFileInfo $file
				 */
				if($file->isFile())
				{
					self::create($pathTo . DIRECTORY_SEPARATOR . $iterator->getSubPath());
					rename((string)$file, $pathTo . DIRECTORY_SEPARATOR . $iterator->getSubPathName());
				}
				else
				{
					self::remove($file->getPathname());
				}
			}
		}
	}
	
	/**
	 * Copy contents of one directory to another recursively without removing target directory's contents
	 *
	 * @param string $pathFrom
	 * @param string $pathTo
	 * @param ?callable $filenameCallback
	 *
	 * @return void
	 */
	public static function copyFiles(
		string $pathFrom,
		string $pathTo,
		?callable $filenameCallback = null,
	): void
	{
		$pathFrom = self::preProcess($pathFrom);
		$pathTo = self::preProcess($pathTo);

		if(is_dir($pathFrom) && is_dir($pathTo))
		{
			$directoryIterator = new RecursiveDirectoryIterator($pathFrom, FilesystemIterator::SKIP_DOTS);
			/**
			 * @var RecursiveDirectoryIterator $iterator
			 */
			foreach($iterator = new RecursiveIteratorIterator($directoryIterator, RecursiveIteratorIterator::SELF_FIRST) as $file)
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
					copy((string)$file, $pathTo
						. DIRECTORY_SEPARATOR . $iterator->getSubPath()
						. DIRECTORY_SEPARATOR . $filename
					);
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
	 * @param bool $skipHidden
	 * @param ?callable $skipCallback
	 * @param ?callable $filenameCallback Callback function for filename processing
	 * @param int $filter
	 *
	 * @return array
	 */
	public static function getDirectoriesTree(string $path,
		$skipHidden = true,
		?callable $skipCallback = null,
		?callable $filenameCallback = null,
		$filter = self::FILTER_FILES
	): array
	{
		return self::getTree(...func_get_args());
	}
	
	/**
	 * @param string $path
	 * @param bool $skipHidden
	 * @param ?callable $skipCallback
	 * @param ?callable $filenameCallback Callback function for filename processing
	 * @param int $filter
	 *
	 * @return array
	 */
	public static function getTree(string $path,
		$skipHidden = true,
		?callable $skipCallback = null,
		?callable $filenameCallback = null,
		$filter = self::FILTER_NONE
	): array
	{
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
			if($filter && $filter === self::FILTER_FILES
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
			$files[$filename] = self::getTree(
				$file->getPathname(),
				$skipHidden,
				$skipCallback,
				$filenameCallback,
				$filter
			);
		}
		// directories first
		krsort($files, SORT_NATURAL);
		
		return $files;
	}

	/**
	 * @param string $path
	 * @param bool $skipHidden
	 * @param ?callable $skipCallback
	 * @param int $filter
	 *
	 * @return array
	 */
	public static function getFiles(string $path,
		$skipHidden = true,
		?callable $skipCallback = null,
		$filter = self::FILTER_NONE
	): array
	{
		$files = [];

		$directoryIterator = new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS);
		/**
		 * @var RecursiveDirectoryIterator $iterator
		 */
		foreach($iterator = new RecursiveIteratorIterator($directoryIterator, RecursiveIteratorIterator::CHILD_FIRST) as $file)
		{
			// hidden files, eg. ".gitkeep"
			if($skipHidden && $file->getFilename()[0] === '.')
			{
				continue;
			}
		
			/**
			 * @var SplFileInfo $file
			 */
			if($filter && $file->isDir() === ($filter === self::FILTER_FILES))
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
