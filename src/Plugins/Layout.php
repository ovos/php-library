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
		$response = $this->app->getResponse();
		
		// A response the ACTION already sent has no page to wrap: an SSE
		// stream, a file, a body written by hand. Application::sendResponse()
		// has always honoured isSent(); this ran before it and did not, so the
		// library built a whole document — the layout, every partial, every
		// placeholder — and threw it away, after the request was answered.
		//
		// That is not only waste. Anything the render touches on the way can
		// throw, and it throws with the response long gone: a profiler stream
		// that outlived a deploy reported `Class "Console\Brand" not found`
		// from a page nobody was ever going to receive
		// (docs/plans/layout-skips-a-sent-response.md).
		if($response->isSent())
		{
			return;
		}
		
		foreach($this->layout::placeholders()->toArray()
			as $placeholder => $value)
		{
			$this->layout->$placeholder = $value;
		}
		
		if(get_class($response) === Response\Html::class)
		{
			$this->layout->{self::CONTENT_PLACEHOLDER}.= $response->get();
			$response->set($this->layout->__toString());
		}
	}
}
