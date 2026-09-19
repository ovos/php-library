<?php
declare(strict_types=1);

namespace Tests\Plugins;

use Ovos\Plugins\Layout as Subject;
use Ovos\Response;
use Ovos\Test;
use Ovos\View;
use ReflectionClass;

use function str_contains;

/**
 * Layout - what the plugin wraps, and what it leaves alone.
 *
 * The one it used to get wrong: a response the ACTION already sent. An SSE
 * stream writes its own headers and calls setIsSent(true) precisely so "the
 * framework must not also render/append onto this stream", and
 * Application::sendResponse() honours that. postDispatch() ran before it and
 * did not, so after every such request the library built a whole document -
 * the layout, its partials, its placeholders - and discarded it.
 *
 * Which was not merely waste: anything the render touches can throw, and it
 * throws long after the response is gone. A profiler stream that outlived a
 * deploy reported `Class "Console\Brand" not found` from a page nobody was
 * ever going to receive, on a console whose every other page rendered that
 * same line (docs/plans/layout-skips-a-sent-response.md).
 *
 * So the render is counted here, not just its result: "the body came back
 * unchanged" would also pass if the layout had been built and thrown away,
 * and building it is the bug.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Layout extends Test
{
	/**
	 * A plugin around a counting layout, with no view script anywhere near it
	 * - Layout's own constructor loads one, and the question here is what
	 * postDispatch() decides, not what a .phtml renders
	 */
	protected function plugin(
		View $layout,
	): Subject
	{
		$reflection = new ReflectionClass(Subject::class);
		$plugin = $reflection->newInstanceWithoutConstructor();
		
		$app = $reflection->getParentClass()->getProperty('app');
		$app->setValue($plugin, $this->app);
		$reflection->getProperty('layout')->setValue($plugin, $layout);
		
		return $plugin;
	}
	
	/** an Html response the app has answered with, and not yet sent */
	protected function response(
		string $body,
	): Response\Html
	{
		$response = new Response\Html;
		$response->set($body);
		$this->app->setResponse($response);
		
		return $response;
	}
	
	/**
	 * The ordinary path: the action's body lands in the content placeholder
	 * and the whole page becomes the response.
	 */
	public function anUnsentResponseIsWrappedInTheLayout(): bool
	{
		$layout = new CountingLayout;
		$response = $this->response('THE ACTION');
		$this->plugin($layout)->postDispatch();
		
		return $layout->renders === 1
			&& str_contains($response->get(), 'THE ACTION')
			&& str_contains($response->get(), CountingLayout::PAGE);
	}
	
	/**
	 * RULE: a response the action already sent is not touched, and the layout
	 * is NEVER RENDERED. An SSE stream, a file, a body written by hand - the
	 * bytes are gone, so there is no page to wrap and nothing to gain by
	 * building one.
	 *
	 * Prevents: a stream endpoint paying for a full page render per request,
	 * and an exception raised inside that render being reported against a
	 * request that succeeded minutes earlier.
	 */
	public function aSentResponseIsLeftAloneAndNeverRendered(): bool
	{
		$layout = new CountingLayout;
		$response = $this->response('data: {"tick":1}');
		$response->setIsSent(true);
		$this->plugin($layout)->postDispatch();
		
		return $layout->renders === 0
			&& $response->get() === 'data: {"tick":1}';
	}
	
	/**
	 * …and the older rule still holds: only an Html response wears a layout,
	 * so a JSON answer keeps its own body whether or not it was sent.
	 */
	public function aJsonResponseIsNotWrappedEither(): bool
	{
		$layout = new CountingLayout;
		$response = new Response\Json;
		$response->answer = 'forty-two';
		$this->app->setResponse($response);
		$this->plugin($layout)->postDispatch();
		
		return $layout->renders === 0
			&& $response->answer === 'forty-two';
	}
}

/**
 * A layout that renders a known page and says how many times it was asked to.
 * The count is the assertion the response alone cannot make.
 */
class CountingLayout extends View\Layout
{
	public const string PAGE = '<!doctype html><title>page</title>';
	
	public int $renders = 0;
	
	public function __construct()
	{
		parent::__construct();
	}
	
	public function __toString(): string
	{
		$this->renders++;
		
		return self::PAGE . $this->{Subject::CONTENT_PLACEHOLDER};
	}
}
