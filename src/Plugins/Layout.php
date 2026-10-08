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
		// a fresh page: nothing a controller before this one put into the
		// placeholders, the body or the elements carries over. The error page
		// builds its layout after the failed controller's plugins ran, and
		// User's Layout\Homepage had added `with-header-widget` to <body> —
		// the class that hides the top bar for a header widget the clear
		// placeholders no longer held, so bo2go's 404 under /user lost its
		// logo (bo2go docs/plans/ERROR.PAGE.LAYOUT.PLAN.md)
		$this->layout::placeholders()->clear();
		$this->layout::body()->clear();
		$this->layout::elements()->clear();
		
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
