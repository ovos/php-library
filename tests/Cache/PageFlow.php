<?php
declare(strict_types=1);

namespace Tests\Cache;

use Ovos\Application;
use Ovos\Cache\Page as PageAttribute;
use Ovos\Cache\Stale;
use Ovos\Cache\Store\KeyValue\Tags;
use Ovos\Cache\Store\Redis as Store;
use Ovos\Controller;
use Ovos\Plugins\Cache\Page as Subject;
use Ovos\Request;
use Ovos\Response;
use Ovos\Response\Html;
use Ovos\Test;
use Ovos\Test\Cache\Store\TraitRedis;
use Ovos\Test\Internal;
use Closure;
use Override;

use function apcu_delete;
use function apcu_store;
use function count;
use function microtime;
use function Ovos\container;
use function time;

/**
 * Page cache - the plugin's dispatch: preDispatch and postDispatch driven
 * around a stand-in action, over a real Redis store
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class PageFlow extends Test
{
	use TraitRedis;
	
	protected const string KEY = 'page:flow';
	
	protected ?Response $runnerResponse = null;
	
	/**
	 * RULE: a fresh page is served without the action
	 */
	public function aFreshPageIsServedWithoutTheAction(): bool
	{
		$store = $this->pageStore();
		$this->writePage($store, 'cached', fresh: true);
		
		$request = $this->request($store);
		$this->dispatch($request, 'rendered');
		$this->clean($store);
		
		return $request->controller->isDispatched() === true
			&& (string)$request->app->current === 'cached';
	}
	
	/**
	 * RULE: a page past its ttl reaches the elected request's visitor first -
	 * sent early, the client released - and is rendered after it, stored
	 * fresh, the lock released
	 */
	public function anAgedPageReachesItsVisitorFirstAndIsRenderedAfter(): bool
	{
		$store = $this->pageStore();
		$this->writePage($store, 'old', fresh: false);
		
		$request = $this->request($store);
		$this->dispatch($request, 'fresh');
		$stored = $this->pageStore()->peek(self::KEY);
		$free = $this->lockFree();
		$this->clean($store);
		
		return count($request->app->finished) === 1
			&& (string)$request->app->finished[0] === 'old'
			&& $request->rendered === true
			&& $stored instanceof Stale
			&& $stored->isFresh() === true
			&& ($stored->value['body'] ?? null) === 'fresh'
			&& $free === true;
	}
	
	/**
	 * RULE: while another request renders it, a page past its ttl is served
	 * as it is - the action does not run
	 */
	public function anAgedPageIsServedWhileAnotherRequestRendersIt(): bool
	{
		$store = $this->pageStore();
		$other = $this->pageStore();
		$this->writePage($store, 'old', fresh: false);
		$held = $other->getMemoLock()->tryLock($other->itemId(self::KEY));
		
		$request = $this->request($store);
		$this->dispatch($request, 'fresh');
		$other->getMemoLock()->releaseActiveLock($other->itemId(self::KEY));
		$this->clean($store);
		
		return $held === true
			&& $request->rendered === false
			&& (string)$request->app->current === 'old'
			&& $request->app->finished === [];
	}
	
	/**
	 * RULE: where the client cannot be released early (mod_php) the elected
	 * visitor gets the rendered page - nothing goes out before it
	 */
	public function withoutAnEarlyFinishTheElectedVisitorGetsTheRender(): bool
	{
		$store = $this->pageStore();
		$this->writePage($store, 'old', fresh: false);
		
		$request = $this->request($store, canFinish: false);
		$this->dispatch($request, 'fresh');
		$stored = $this->pageStore()->peek(self::KEY);
		$this->clean($store);
		
		return $request->app->finished === []
			&& $request->rendered === true
			&& (string)$request->app->current === 'fresh'
			&& $stored instanceof Stale
			&& ($stored->value['body'] ?? null) === 'fresh';
	}
	
	/**
	 * RULE: an invalidation landing while the page renders refuses the
	 * rendered page - the page from before the edit never comes back
	 */
	public function anInvalidationDuringTheRenderRefusesThePage(): bool
	{
		$store = $this->pageStore();
		$this->writePage($store, 'old', fresh: false);
		
		$request = $this->request($store);
		$this->dispatch($request, 'rendered before the edit', function(): void
		{
			// an editor saves while the action renders
			$this->pageStore()->delete(self::KEY);
		});
		$stored = $this->pageStore()->peek(self::KEY);
		$free = $this->lockFree();
		$this->clean($store);
		
		return $request->rendered === true
			&& $stored === null
			&& $free === true;
	}
	
	/**
	 * RULE: a miss renders under the page's lock - another request finds it
	 * taken while the action runs - and the stored page releases it
	 */
	public function aMissRendersUnderThePagesLock(): bool
	{
		$store = $this->pageStore();
		$this->clean($store);
		
		$request = $this->request($store);
		$takenWhileRendering = null;
		$this->dispatch($request, 'fresh', function() use (&$takenWhileRendering, $store): void
		{
			$takenWhileRendering = $this->lockFree() === false;
		});
		$stored = $this->pageStore()->peek(self::KEY);
		$free = $this->lockFree();
		$this->clean($store);
		
		return $takenWhileRendering === true
			&& ($stored instanceof Stale ? $stored->value['body'] ?? null : null) === 'fresh'
			&& $free === true;
	}
	
	/**
	 * RULE: a page that cannot be stored (not a 200, a cookie) releases the
	 * lock its miss took - the next request renders at once, rather than
	 * after the lock's TTL
	 */
	public function anUncacheablePageReleasesTheLock(): bool
	{
		$store = $this->pageStore();
		$this->clean($store);
		
		$request = $this->request($store);
		$this->dispatch($request, 'error', null, 500);
		$stored = $this->pageStore()->peek(self::KEY);
		$free = $this->lockFree();
		$this->clean($store);
		
		return $request->rendered === true
			&& $stored === null
			&& $free === true;
	}
	
	/**
	 * RULE: the elected request looks in the store once more - a page an
	 * invalidation removed behind this worker's APCu copy is not served stale
	 * but rendered, its visitor waiting for it
	 */
	public function aPageInvalidatedBehindTheWorkersCopyIsRendered(): bool
	{
		$store = $this->pageStore();
		$this->clean($store);
		$this->writeApcu('old', fresh: false);
		
		$request = $this->request($store, apcu: 5);
		$this->dispatch($request, 'fresh');
		$this->clean($store);
		$this->deleteApcu();
		
		return $request->app->finished === []
			&& $request->rendered === true
			&& (string)$request->app->current === 'fresh';
	}
	
	/**
	 * RULE: a page another worker rendered behind this worker's APCu copy is
	 * served - not rendered again
	 */
	public function aPageRenderedBehindTheWorkersCopyIsServed(): bool
	{
		$store = $this->pageStore();
		$this->writePage($store, 'new', fresh: true);
		$this->writeApcu('old', fresh: false);
		
		$request = $this->request($store, apcu: 5);
		$this->dispatch($request, 'rendered again');
		$free = $this->lockFree();
		$this->clean($store);
		$this->deleteApcu();
		
		return $request->rendered === false
			&& (string)$request->app->current === 'new'
			&& $free === true;
	}
	
	/**
	 * RULE: a page stored with a stale time carries it - its record says how
	 * long it may be served past its ttl
	 */
	public function aPageCarriesItsStaleTime(): bool
	{
		$store = $this->pageStore();
		$this->clean($store);
		
		$this->dispatch($this->request($store, stale: 45), 'rendered');
		$stored = $this->pageStore()->peek(self::KEY);
		$this->clean($store);
		
		return $stored instanceof Stale
			&& $stored->staleFor === 45
			&& $stored->isFresh() === true;
	}
	
	/**
	 * RULE: a page past its stale time is rendered, not served - while its
	 * item is still there
	 */
	public function aPagePastItsStaleTimeIsRenderedNotServed(): bool
	{
		$store = $this->pageStore();
		$store->set(self::KEY, new Stale([
			'code' => 200,
			'headers' => [],
			'savedAt' => time(),
			'body' => 'too old',
		], microtime(true) - 100, 60), 120);
		
		$request = $this->request($store);
		$this->dispatch($request, 'fresh');
		$stored = $this->pageStore()->peek(self::KEY);
		$this->clean($store);
		
		return $request->rendered === true
			&& $request->app->finished === []
			&& (string)$request->app->current === 'fresh'
			&& $stored instanceof Stale
			&& ($stored->value['body'] ?? null) === 'fresh';
	}
	
	/**
	 * RULE: without a stale time a page is stored plain, and served as it is
	 */
	public function aPageWithoutAStaleTimeIsStoredPlain(): bool
	{
		$store = $this->pageStore();
		$this->clean($store);
		
		$this->dispatch($this->request($store, stale: 0), 'plain');
		$stored = $this->pageStore()->peek(self::KEY);
		$hit = $this->request($store, stale: 0);
		$this->dispatch($hit, 'rendered again');
		$this->clean($store);
		
		return ($stored['body'] ?? null) === 'plain'
			&& $hit->rendered === false
			&& (string)$hit->app->current === 'plain';
	}
	
	/**
	 * Called by the runner before each test method
	 */
	#[Internal]
	#[Override]
	public function prepare(): void
	{
		// every Response registers itself with the application; the runner's
		// own is put back after each rule (see finalize())
		$this->runnerResponse = Application::$instance?->getResponse();
	}
	
	/**
	 * Called by the runner after each test method
	 */
	#[Internal]
	#[Override]
	public function finalize(): void
	{
		if($this->runnerResponse !== null)
		{
			Application::$instance?->setResponse($this->runnerResponse);
		}
	}
	
	/**
	 * A fresh store - another process
	 */
	protected function pageStore(): Store
	{
		return $this->getStore(Store::class);
	}
	
	/**
	 * Writes a stored page, fresh or past its ttl
	 */
	protected function writePage(
		Store $store,
		string $body,
		bool $fresh,
	): void
	{
		// as the plugin stores it: with its stale time (see Page::store())
		$store->set(self::KEY, new Stale([
			'code' => 200,
			'headers' => [],
			'savedAt' => time(),
			'body' => $body,
		], microtime(true) + ($fresh ? 60 : -1), 60), 120);
	}
	
	/**
	 * Writes this worker's APCu copy of the page (the plugin's front tier)
	 */
	protected function writeApcu(
		string $body,
		bool $fresh,
	): void
	{
		apcu_store(Subject::KEY_PREFIX . 'apcu:' . self::KEY, new Stale([
			'code' => 200,
			'headers' => [],
			'savedAt' => time(),
			'body' => $body,
		], microtime(true) + ($fresh ? 60 : -1), 60), 5);
	}
	
	protected function deleteApcu(): void
	{
		apcu_delete(Subject::KEY_PREFIX . 'apcu:' . self::KEY);
	}
	
	protected function lockFree(): bool
	{
		$probe = $this->pageStore();
		$id = $probe->itemId(self::KEY);
		$free = $probe->getMemoLock()->tryLock($id);
		$probe->getMemoLock()->releaseActiveLock($id);
		
		return $free;
	}
	
	protected function clean(
		Store $store,
	): void
	{
		$store->getClient()->del($store->itemId(self::KEY));
	}
	
	/**
	 * One request through the plugin: preDispatch, then - unless it served
	 * the page - the action ($during runs while it renders) and postDispatch
	 */
	protected function dispatch(
		object $request,
		string $body,
		?Closure $during = null,
		int $code = 200,
	): void
	{
		$request->plugin->preDispatch();
		if($request->controller->isDispatched() === true)
		{
			return;
		}
		
		$request->rendered = true;
		if($during !== null)
		{
			$during();
		}
		$rendered = new Html($body);
		$rendered->setHttpCode($code);
		$request->app->setResponse($rendered);
		$request->plugin->postDispatch();
	}
	
	/**
	 * A request: the plugin with a stand-in application, controller and
	 * HTTP request around it
	 *
	 * @return object{plugin: Subject, app: Application, controller: Controller, rendered: bool}
	 */
	protected function request(
		Store $store,
		bool $canFinish = true,
		int $stale = 60,
		int $apcu = 0,
	): object
	{
		$app = new class($canFinish) extends Application
		{
			public ?Response $current = null;
			
			/** @var array<Response> what went out early */
			public array $finished = [];
			
			public function __construct(
				public bool $canFinish,
			)
			{
			}
			
			#[Override]
			public function getResponse(
				?string $response = null,
			): Response
			{
				return $this->current ?? new Html('');
			}
			
			#[Override]
			public function setResponse(
				Response $response,
			): static
			{
				$this->current = $response;
				
				return $this;
			}
			
			#[Override]
			public function finishResponse(
				Response $response,
			): bool
			{
				if($this->canFinish === false)
				{
					return false;
				}
				
				$this->finished[] = $response;
				
				return true;
			}
		};
		$controller = new class extends Controller
		{
			public function __construct()
			{
			}
		};
		$http = new class extends Request
		{
			#[Override]
			public function getServer(
				string $name,
			): ?string
			{
				return $name === 'REQUEST_METHOD' ? 'GET' : null;
			}
		};
		$plugin = new class($app, $http, $controller, $store, new PageAttribute(ttl: 60, stale: $stale, apcu: $apcu), self::KEY) extends Subject
		{
			public function __construct(
				Application $app,
				Request $request,
				Controller $controller,
				protected Tags $testStore,
				protected PageAttribute $testAttribute,
				protected string $testKey,
			)
			{
				$this->container = container();
				$this->app = $app;
				$this->request = $request;
				$this->controller = $controller;
			}
			
			#[Override]
			protected function resolveAttribute(): ?PageAttribute
			{
				return $this->testAttribute;
			}
			
			#[Override]
			protected function getStore(): ?Tags
			{
				return $this->testStore;
			}
			
			#[Override]
			protected function buildKey(): string
			{
				return $this->testKey;
			}
		};
		
		return (object)[
			'plugin' => $plugin,
			'app' => $app,
			'controller' => $controller,
			'rendered' => false,
		];
	}
}
