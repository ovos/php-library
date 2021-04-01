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

	public function copy()
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
	
	public function move()
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
}
