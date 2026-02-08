<?php

namespace Ovos;

use function count;
use function str_replace;
use function array_keys;
use function array_values;

/**
 * Command
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Command
{
	protected Application $app;
	
	protected string $name;
	
	protected array $params;
	
	public function __construct(
		string $name,
		array $params = [],
	)
	{
		$this->app = app();
		
		$this->setName($name);
		$this->setParams($params);
	}
	
	public function setName(
		string $name,
	): static
	{
		$this->name = $name;
		
		return $this;
	}
	
	public function setParams(
		array $params,
	): static
	{
		$this->params = $params;
		
		return $this;
	}
	
	public function getConfig(): ArrayObject
	{
		return $this->app->getConfig()->commands;
	}
	
	public function get(): string
	{
		$commands = $this->getConfig();
		if(isset($commands[$this->name]) === false)
		{
			return '';
		}
		
		$command = $commands[$this->name];
		
		if(count($this->params))
		{
			$command = str_replace(
				array_keys($this->params),
				array_values($this->params),
				$command,
			);
		}
		
		return $command;
	}
	
	public function __toString(): string
	{
		return $this->get();
	}
}
