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
