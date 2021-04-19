<?php
declare(strict_types=1);

namespace Ovos\Logger;

use Ovos\Dir;

/**
 * Trait File
 *
 * @package Wirecard
 * @author Marcin Gil <mg@ovos.at>
 */
trait File
{
	/**
	 * @var string
	 */
	protected ?string $_absoluteDir = null;	

	/**
	 * @return string
	 */
	public function getAbsoluteDir(): string
	{
		if($this->_absoluteDir === null)
		{
			$this->_absoluteDir = BASE_DIR . 'application' . DIRECTORY_SEPARATOR
				. 'logs' . DIRECTORY_SEPARATOR
				. $this->_dir . DIRECTORY_SEPARATOR;
			
			Dir::create($this->_absoluteDir);	
		}
	
		return $this->_absoluteDir;
	}

	/**
	 * @param string $output
	 */
	public function output(string $output): void
	{
		$filename = $this->getAbsoluteDir()
			. $this->_file . '_' . date('Y_m_d') . '.txt';
		file_put_contents($filename, $output, FILE_APPEND);
	}
}
