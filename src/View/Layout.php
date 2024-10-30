<?php
declare(strict_types=1);

namespace Ovos\View;

use Ovos\View;

/**
 * View
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Layout extends View
{
	/**
	 * @param ?string $viewScriptFile
	 */
	public function __construct(?string $viewScriptFile = null)
	{
		if($viewScriptFile !== null)
		{
			$viewScriptFile = 'layouts' . DIRECTORY_SEPARATOR . $viewScriptFile;
		}
		
		parent::__construct($viewScriptFile);
		
		$this->host = SYSTEM_HOST . SYSTEM_PATH;
	}
}
