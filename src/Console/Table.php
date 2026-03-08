<?php
declare(strict_types=1);

namespace Ovos\Console;

use Symfony\Component\Console\Helper\Table as SymfonyTable;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Table
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Table
{
	protected SymfonyTable $table;
	protected BufferedOutput $output;
	protected bool $hasMarkup = false;
	
	public function __construct()
	{
		$this->output = new BufferedOutput(BufferedOutput::VERBOSITY_NORMAL, true);
		$this->table = new SymfonyTable($this->output);
	}
	
	public function hasMarkup(
		bool $hasMarkup,
	): static
	{
		$this->hasMarkup = $hasMarkup;
		
		return $this;
	}
	
	public function setHeaders(
		array $headers,
	): void
	{
		$this->table->setHeaders($headers);
	}
	
	public function addRow(
		array $row,
	): void
	{
		$this->table->addRow($row);
	}
	
	public function getTable(): string
	{
		$this->table->render();
		
		return $this->output->fetch();
	}
}
