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
	public function __construct(
		?string $response = null,
	)
	{
		parent::__construct($response);
		
		$this->setHeader('Content-Type',
			'text/csv; charset=utf-8');
	}
	
	public function setFilename(
		string $filename,
	): void
	{
		$this->setHeader('Content-Disposition',
			'attachment; filename=' . $filename);
	}
}
