<?php
declare(strict_types=1);

namespace Ovos\Response;

/**
 * Calendar
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Calendar extends Html
{
	public function __construct(
		?string $response = null,
	)
	{
		parent::__construct($response);
		
		$this->setHeader('Content-Type',
			'text/calendar; charset=utf-8');
	}
	
	public function setFilename(
		string $filename,
	): void
	{
		$this->setHeader('Content-Disposition',
			'attachment; filename=' . $filename);
	}
}
