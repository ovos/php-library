<?php
declare(strict_types=1);

namespace Ovos\Response;

/**
 * Csv
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Csv extends Html
{
	/**
	 * Construct
	 *
	 * @param ?string $response
	 */
	public function __construct(?string $response = null)
	{
		parent::__construct($response);
		
		$this->setHeader('Content-Type', 'text/csv; charset=utf-8');
	}
	
	/**
	 * @param string $filename
	 */
	public function setFilename(string $filename)
	{
		$this->setHeader('Content-Disposition',  'attachment;filename=' . $filename);
	}
}
