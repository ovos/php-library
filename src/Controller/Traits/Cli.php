<?php
declare(strict_types=1);

namespace Ovos\Controller\Traits;

use Ovos\Terminal;
use SplFileInfo;
use FilesystemIterator;
use function count;
use function str_starts_with;
use function printf;

/**
 * Trait Cli
 * Additional tools for user-interacting CLI
 * Warning: by default including this trait turns on the colored output
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
trait Cli
{
	/**
	 * @param mixed $default
	 * 
	 * @return ?string
	 */
	public function readLine(mixed $default = null): ?string
	{
		$line = Terminal::readLine();
		if($line === null)
		{
			return $default;
		}
		
		return $line;
	}

	/**
	 * @param string $dir
	 * @param bool $selectDirectory
	 *
	 * @return array
	 */
	public function listFiles(string $dir, bool $selectDirectory = false): array
	{
		$files = [];

		$iterator = new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);
		foreach($iterator as $file)
		{
			/**
			 * @var SplFileInfo $file
			 */
			if($file->isDir() !== $selectDirectory)
			{
				continue;
			}
			
			if(str_starts_with($file->getBasename(), '.')) // skip hidden files
			{
				continue;
			}

			$files[] = $file;
		}
		
		return $files;
	}
	
	/**
	 * @param string $dir
	 * @param bool $selectDirectory
	 *
	 * @return ?SplFileInfo
	 */
	public function selectFile(string $dir, bool $selectDirectory = false): ?SplFileInfo
	{
		$files = $this->listFiles($dir, $selectDirectory);
		if(count($files) === 0)
		{
			return null;
		}

		$this->log('Please pick a %s (type the number and hit <blue>ENTER<reset>):', 
			$selectDirectory ? 'directory' : 'file');
			
		foreach($files as $key => $file)
		{
			printf("\t%d. %s" . PHP_EOL, $key + 1, $file->getBasename());
		}

		$selection = (int)$this->readLine() - 1;
		if(isset($files[$selection]))
		{
			return $files[$selection];
		}
	}
	
	/**
	 * @param string $dir
	 *
	 * @return ?SplFileInfo
	 */
	public function selectDirectory(string $dir): ?SplFileInfo
	{
		return $this->selectFile($dir, true);
	}
}
