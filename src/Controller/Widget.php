<?php
declare(strict_types=1);

namespace Ovos\Controller;

use Ovos\Application;
use Ovos\Container;
use Ovos\Request;
use Ovos\Translatable;

use function Ovos\container;

/**
 * Widget
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Widget
{
	use Translatable;
	
	protected Container $container;
	
	protected Application $app;
	
	protected Request $request;
	
	protected string $script;
	
	/**
	 */
	public function __construct()
	{
		$this->container = container();
		$this->app = $this->container->get(Application::class);
		
		$this->request = $this->app->getRequest();
	}
	
	public function setScript(
		string $script,
	): static
	{
		$this->script = $script;
		
		return $this;
	}
	
	public function getScript(): string
	{
		return $this->script;
	}
	
	abstract public function render(): string;
	
	abstract public function __toString(): string;
}
