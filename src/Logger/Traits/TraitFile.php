<?php
declare(strict_types=1);

namespace Ovos\Logger\Traits;

use Ovos\Dir;

use function date;
use function file_put_contents;

/**
 * Trait File
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
trait TraitFile
{
	protected ?string $absoluteDir = null;
	
	public function getAbsoluteDir(): string
	{
		if($this->absoluteDir === null)
		{
			$this->absoluteDir = BASE_DIR
				. 'application' . DIRECTORY_SEPARATOR
				. 'logs' . DIRECTORY_SEPARATOR
				. $this->dir . DIRECTORY_SEPARATOR;
			
			Dir::create($this->absoluteDir);
		}
	
		return $this->absoluteDir;
	}
	
	public function output(
		string $output,
	): void
	{
		$filename = $this->getAbsoluteDir()
			. $this->file . '_' . date('Y_m_d') . '.txt';
		file_put_contents($filename, $output, FILE_APPEND);
	}
}
