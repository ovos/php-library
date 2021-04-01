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
	 */
	public function __construct()
	{
		parent::__construct();
		
		$this->setColoredOutput(true);
	}

	/**
	 * @param mixed $default
	 * 
	 * @return null|string
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
	 *
	 * @return null|SplFileInfo
	 */
	public function selectFile(string $dir): ?SplFileInfo
	{
		$files = [];

		$iterator = new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);
		foreach($iterator as $file)
		{
			/**
			 * @var SplFileInfo $file
			 */
			if($file->isDir())
			{
				continue;
			}
			
			if(str_starts_with($file->getBasename(), '.')) // skip hidden files
			{
				continue;
			}

			$files[] = $file;
		}

		if(count($files))
		{
			$this->log('Please pick a file (type the number and hit <blue>ENTER<reset>):');
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

		return null;
	}
}
