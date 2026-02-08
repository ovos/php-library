<?php
declare(strict_types=1);

namespace Ovos\Plugins;

use Ovos\Controller\Plugin;
use Ovos\Response;
use Ovos\View;
use Override;

use function get_class;

/**
 * Layout
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Layout extends Plugin
{
	public const string SYMBOL = 'layout';
	
	public const string CONTENT_PLACEHOLDER = 'content';
	
	protected View $layout;
	
	/**
	 * Automatically create these placeholders
	 */
	protected array $placeholders = [];
	
	public function __construct(
		string $layout,
	)
	{
		parent::__construct();
		
		// disable on XMLHttpRequest
		if($this->request->isXmlHttpRequest())
		{
			$this->disable();
			return;
		}
		
		$this->layout = new View\Layout($layout);
		$this->layout::placeholders()->clear();
		
		// automatically create these placeholders
		foreach($this->placeholders as $placeholder)
		{
			$this->layout::placeholders()->{$placeholder};
		}
	}
	
	public function getLayout(): View
	{
		return $this->layout;
	}
	
	#[Override] 
	public function preDispatch(): void
	{
	}
	
	#[Override] 
	public function postDispatch(): void
	{
		foreach($this->layout::placeholders()->toArray()
			as $placeholder => $value)
		{
			$this->layout->$placeholder = $value;
		}
		
		$response = $this->app->getResponse();
		if(get_class($response) === Response\Html::class)
		{
			$this->layout->{self::CONTENT_PLACEHOLDER}.= $response->get();
			$response->set($this->layout->__toString());
		}
	}
}
