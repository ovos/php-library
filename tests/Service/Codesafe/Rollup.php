<?php
declare(strict_types=1);

namespace Tests\Service\Codesafe;

use Ovos\ArrayObject;
use Ovos\Controller;
use Ovos\Request;
use Ovos\Service\Codesafe\Rollup as CodesafeRollup;
use Ovos\Test;

use function array_fill;
use function str_repeat;

/**
 * Rollup — the pure half of the traffic-rollup accumulator: the opt-in
 * gate, the closed-vocabulary route naming, the identity sanitizer and the
 * counter-fields-to-fragment mapping. The APCu machinery (accumulation,
 * the minute-boundary flush, the add()-lock) is exercised against a real
 * pool, not here — apcu is usually absent on the CLI, and a mocked APCu
 * would test the mock.
 *
 * The one invariant everything below serves: no request-derived string may
 * become a counter field name. The console refuses fragments that violate
 * it wholesale, so a client that let one through would not error — it
 * would silently lose whole minutes.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Rollup extends Test
{
	/**
	 * RULE: the Shield's hits ride the fragment — the flush hook's `so:<id>` /
	 * `sb:<id>` fields join the minute's counters (a throwing hook adds
	 * nothing) and assemble() maps them into the shield_observe /
	 * shield_block sections, absent when no rule fired. Falsify: assemble the
	 * two prefixes as routes — `r:so:203` ships and the console refuses the
	 * whole fragment as a route key outside the vocabulary.
	 */
	public function theShieldHitsRideTheFragment(): bool
	{
		$fields = CodesafeRollup::withExtra(['requests' => 3, 's:200' => 3],
			static fn(int $minute): array => ['so:203' => 4, 'sb:203' => 1, 'so:7' => 0, 'minute' => $minute],
			29833333);
		$payload = CodesafeRollup::assemble(29833333, $fields);
		$plain = CodesafeRollup::assemble(29833333, ['requests' => 3]);
		$broken = CodesafeRollup::withExtra(['requests' => 3], static function(): array
		{
			throw new \RuntimeException('no apcu');
		}, 29833333);
		
		return $fields === ['requests' => 3, 's:200' => 3, 'so:203' => 4, 'sb:203' => 1, 'minute' => 29833333]
			&& $payload['shield_observe'] === ['203' => 4] && $payload['shield_block'] === ['203' => 1]
			&& $payload['requests'] === 3 && $payload['routes'] === []
			&& isset($plain['shield_observe']) === false && isset($plain['shield_block']) === false
			&& $broken === ['requests' => 3]
			&& CodesafeRollup::withExtra(['requests' => 1], null, 1) === ['requests' => 1];
	}
	
	/**
	 * Rollups are opt-in TWICE (here and on the console project) and need
	 * the direct transport: an OTLP-only sender has no url/key and exports
	 * console.rollup.requests through its collector instead.
	 */
	public function rollupsNeedTheOptInAndTheDirectTransport(): bool
	{
		$enabled = static fn(array $config): bool
			=> (new CodesafeRollup(new ArrayObject($config)))->isEnabled();
		
		$base = [
			'enabled' => true,
			'rollups' => true,
			'url' => 'https://console.invalid',
			'key' => 'test-key',
		];
		
		return $enabled($base)
			&& $enabled(['rollups' => false] + $base) === false
			// absent means OFF — an upgrade must not start counting
			&& $enabled(['enabled' => true, 'url' => 'https://console.invalid',
				'key' => 'test-key']) === false
			&& $enabled(['enabled' => false] + $base) === false
			&& $enabled(['url' => ''] + $base) === false
			&& $enabled(['key' => ''] + $base) === false
			&& (new CodesafeRollup(null))->isEnabled() === false;
	}
	
	/**
	 * The controller/action strings on a Request are ROUTER INPUT — on a
	 * 404 they carry whatever the client asked for. Without a constructed
	 * controller instance the route is __unmatched, whatever the strings
	 * say; that counter IS the probe signal the console reads.
	 */
	public function anUnresolvedRequestIsUnmatchedWhateverItClaims(): bool
	{
		$request = new Request;
		$request->setController('wp-admin');
		$request->setAction('setup-config.php');
		
		return CodesafeRollup::routeOf($request) === '__unmatched';
	}
	
	/**
	 * A 404 answer forces __unmatched even when the route LOOKS resolved:
	 * the error-page forward that renders the 404 marks the request with
	 * the error route's own controller/action, so without the status
	 * override every router miss — the exact traffic __unmatched exists to
	 * count — would report as a legitimate route. Any other status trusts
	 * the resolved route.
	 */
	public function aFourOhFourIsUnmatchedWhateverTheRouteClaims(): bool
	{
		// constructing a controller points the SHARED request at it — put
		// the runner's own controller back before anything else can notice
		$shared = $this->app->getRequest();
		$running = $shared->getControllerInstance();
		
		$controller = new class extends Controller
		{
			public function index(): void
			{
			}
		};
		
		$shared->setControllerInstance($running);
		
		$request = new Request;
		$request->setControllerInstance($controller);
		$request->setController('index');
		$request->setAction('index');
		
		return CodesafeRollup::routeOf($request) === '/index/index'
			&& CodesafeRollup::routeFor(404, $request) === '__unmatched'
			&& CodesafeRollup::routeFor(200, $request) === '/index/index'
			// a CLI-ish false status trusts the route too — only 404 overrides
			&& CodesafeRollup::routeFor(false, $request) === '/index/index';
	}
	
	/**
	 * Route names come from code, but the shape is enforced anyway: a name
	 * the console's route rule would refuse collapses to __other, because
	 * one doubtful field name must never cost the whole fragment.
	 */
	public function routeNamesHoldTheConsoleShape(): bool
	{
		return CodesafeRollup::routeName('Errors', 'index') === '/errors/index'
			&& CodesafeRollup::routeName('Api\V1\Ingest', 'rollup') === '/api\v1\ingest/rollup'
			&& CodesafeRollup::routeName('with space', 'index') === '__other'
			&& CodesafeRollup::routeName('query?x', 'index') === '__other'
			&& CodesafeRollup::routeName('escape%2e', 'index') === '__other'
			&& CodesafeRollup::routeName(str_repeat('a', 220), 'index') === '__other'
			&& CodesafeRollup::routeName('', '') === '__other';
	}
	
	/**
	 * host/instance land in a console redis KEY NAME, so the sanitizer is
	 * held to the same identity shape the server enforces — anything else
	 * becomes a dash, and 64 chars is the cap.
	 */
	public function theHostNameIsReducedToTheIdentityShape(): bool
	{
		return CodesafeRollup::hostName('web-03.example.at') === 'web-03.example.at'
			&& CodesafeRollup::hostName('bad host|name') === 'bad-host-name'
			&& CodesafeRollup::hostName(str_repeat('h', 80)) === str_repeat('h', 64)
			&& CodesafeRollup::hostName('') === '';
	}
	
	/**
	 * The collected counter fields map onto exactly the fragment body the
	 * console's validator expects — requests at the top, the four
	 * breakdowns by their prefixes, nothing invented and nothing dropped.
	 */
	public function counterFieldsAssembleIntoTheFragmentBody(): bool
	{
		$payload = CodesafeRollup::assemble(29248320, [
			'requests' => 431,
			's:200' => 52,
			's:404' => 379,
			'm:GET' => 431,
			'r:/product/index' => 52,
			'r:__unmatched' => 379,
			'a:no' => 431,
		]);
		
		return $payload === [
				'minute' => 29248320,
				'requests' => 431,
				'status' => ['200' => 52, '404' => 379],
				'methods' => ['GET' => 431],
				'routes' => ['/product/index' => 52, '__unmatched' => 379],
				'authed' => ['no' => 431],
			]
			// an empty minute still has the shape, so the caller can rely on it
			&& CodesafeRollup::assemble(1, []) === [
				'minute' => 1,
				'requests' => 0,
				'status' => [],
				'methods' => [],
				'routes' => [],
				'authed' => [],
			];
	}
	
	/**
	 * RULE: the duration bounds are a WIRE CONTRACT pinned byte for byte
	 * (the console's Console\Stats\Durations carries the same list), and
	 * bucketFor() classifies "first bound >= value" — pinned AT the
	 * boundaries, because an off-by-one here shifts every percentile the
	 * console will ever show for this sender's projects.
	 */
	public function durationBucketsClassifyAtTheirBoundaries(): bool
	{
		return CodesafeRollup::DURATION_BOUNDS === [25, 50, 100, 200, 400, 800, 1600, 3200, 6400, 12800, 30000]
			&& CodesafeRollup::DURATION_BUCKETS === 12
			&& CodesafeRollup::bucketFor(0.0) === 0
			&& CodesafeRollup::bucketFor(25.0) === 0
			&& CodesafeRollup::bucketFor(25.1) === 1
			&& CodesafeRollup::bucketFor(30000.0) === 10
			&& CodesafeRollup::bucketFor(30000.1) === 11;
	}
	
	/**
	 * RULE: dt:/d: counters assemble into fixed 12-int vectors under the
	 * durations key — the route split on the LAST colon, because route
	 * patterns carry colons of their own — and the key ships ONLY when the
	 * __total headline survived: the console refuses a durations map
	 * without it whole, so a partial APCu eviction must degrade to "no
	 * histograms this minute", never to a rejected fragment that also
	 * loses the traffic counters riding beside it.
	 */
	public function durationFieldsAssembleIntoVectorsOrNotAtAll(): bool
	{
		$payload = CodesafeRollup::assemble(29248320, [
			'requests' => 3,
			'r:/user/:id' => 2,
			'r:/orders' => 1,
			'dt:0' => 1,
			'dt:11' => 2,
			'd:/user/:id:0' => 1,
			'd:/user/:id:11' => 1,
			'd:/orders:11' => 1,
		]);
		
		$vector = static function(array $counts): array
		{
			$vector = array_fill(0, CodesafeRollup::DURATION_BUCKETS, 0);
			foreach($counts as $bucket => $count)
			{
				$vector[$bucket] = $count;
			}
			
			return $vector;
		};
		
		$evicted = CodesafeRollup::assemble(29248320, [
			'requests' => 2,
			'd:/orders:3' => 2,
		]);
		
		// assemble() ksorts its fields, so routes precede the dt:-built total
		return ($payload['durations'] ?? null) === [
				'/orders' => $vector([11 => 1]),
				'/user/:id' => $vector([0 => 1, 11 => 1]),
				'__total' => $vector([0 => 1, 11 => 2]),
			]
			&& isset($evicted['durations']) === false
			&& $evicted['requests'] === 2;
	}
	
	/**
	 * RULE: a streaming response (text/event-stream) is held open for as
	 * long as the client listens — its wall time measures the subscription,
	 * not the work — so observe() counts it as a request and gives it no
	 * duration; otherwise every stream lands in the overflow bucket and one
	 * SSE endpoint owns the project's average. The media type the endpoint
	 * declares is the signal, whatever its case or charset suffix; a header
	 * that merely mentions the token is not.
	 */
	public function aStreamIsRecognisedByItsDeclaredContentType(): bool
	{
		return CodesafeRollup::isStream(['Content-Type: text/event-stream']) === true
			&& CodesafeRollup::isStream(['X-Accel-Buffering: no', 'content-type: TEXT/EVENT-STREAM; charset=utf-8']) === true
			&& CodesafeRollup::isStream(['Content-Type: text/html; charset=utf-8']) === false
			&& CodesafeRollup::isStream(['Content-Type: application/json']) === false
			&& CodesafeRollup::isStream(['Link: </sse>; rel="text/event-stream"']) === false
			&& CodesafeRollup::isStream([]) === false;
	}
	
	/**
	 * RULE: every APCu key carries the install's configured cache prefix.
	 * APCu belongs to the whole FPM pool and a pool can serve several
	 * installs; sharing the keys means they sum each other's counters,
	 * share one flush watermark, and — the one that cannot be repaired
	 * afterwards — ship as the same (instance, seq), which the console
	 * dedups against each other.
	 *
	 * No prefix configured leaves the keys exactly as they were, so an
	 * upgrade changes nothing for an install that names no namespace.
	 */
	public function keysCarryTheInstallNamespace(): bool
	{
		$prefix = static fn(?string $configured): string
			=> (new CodesafeRollup(null, $configured))->getPrefix();
		
		return $prefix('shop') === 'shop:' . CodesafeRollup::PREFIX
			&& $prefix('shop') !== $prefix('tenant-b')
			&& $prefix(null) === CodesafeRollup::PREFIX
			&& $prefix('') === CodesafeRollup::PREFIX;
	}
}
