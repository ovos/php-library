<?php
declare(strict_types=1);

namespace Tests;

use Ovos\Test;
use Ovos\Dir as BaseDir;

/**
 * Dir
 *
 * @package Tests
 * @author Marcin Gil <mg@ovos.at>
 */
class Dir extends Test
{
	/**
	 * @var string
	 */
	protected string $_dir = __DIR__
		. DIRECTORY_SEPARATOR . 'Dir'
		. DIRECTORY_SEPARATOR;

	public function copyFiles()
	{
		$copy = $this->_dir . 'copy';
		$copy2 = $this->_dir . 'copy2';
		BaseDir::create($copy2);
		BaseDir::copyFiles($copy, $copy2);

		$copyFiles = array_keys(BaseDir::getFiles($copy));
		$copy2Files = array_keys(BaseDir::getFiles($copy2));
		BaseDir::remove($copy2);
		
		return $copyFiles === $copy2Files;
	}
	
	public function moveFiles()
	{
		$copy = $this->_dir . 'copy';
		$copy2 = $this->_dir . 'copy2';
		$move = $this->_dir . 'move';
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
	
	public function getTree()
	{
		$copy = $this->_dir . 'copy';
		$tree = [
			'dir2' => [
				0 => 'file1.txt',
			],
			'dir1' => [
				'dir1' => [
					0 => 'file1.txt',
				],
				1 => 'file2.txt',
				0 => 'file1.txt',
			],
			1 => 'file2.txt',
			0 => 'file1.txt',
		];
		
		return $tree === BaseDir::getTree($copy);
	}	
	
	public function getFiles()
	{
		$copy = $this->_dir . 'copy';
		
		return count(BaseDir::getFiles($copy)) === 9;
	}
	
	public function getFilesFilterFiles()
	{
		$copy = $this->_dir . 'copy';
		
		return count(BaseDir::getFiles($copy, filter: BaseDir::FILTER_FILES)) === 6;
	}
}
