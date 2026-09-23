<?php
declare(strict_types=1);

namespace Tests\Service\Console;

use Ovos\ArrayObject;
use Ovos\Console\Shield\Facts;
use Ovos\Console\Shield\Kernel;
use Ovos\Console\Shield\Ruleset;
use Ovos\Console\Shield\Verdict;
use Ovos\Plugins\Console\Shield as Plugin;
use Ovos\Service\Console\Shield as Subject;
use Ovos\Test;
use Ovos\Test\Internal;

use function class_exists;
use function apcu_enabled;
use function count;
use function function_exists;
use function is_file;
use function ksort;
use function str_contains;
use function str_starts_with;
use function sys_get_temp_dir;
use function time;
use function uniqid;
use function unlink;

// the vendored kernel: guarded like every require of it, so a suite that
// already holds a copy (the console's own) does not declare it twice
if(class_exists(Kernel::class, false) === false)
{
	require_once __DIR__ . '/../../../src/Console/Shield/Kernel.php';
}

/**
 * The Shield adapter (Ovos\Service\Console\Shield) and its controller
 * plugin: what switches it on, how the three consent keys read, where the
 * store lives per install, and the verdict path from a request to the
 * Sender's security channel and to the 403 — over an injected transport
 * and a temp-dir store, no network and no application.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Shield extends Test
{
	/** @var list<string> */
	protected array $paths = [];
	
	/** @var list<array{0: string, 1: string, 2: array}> */
	protected array $reports = [];
	
	#[Internal]
	public function deconstruct(): void
	{
		foreach($this->paths as $path)
		{
			if(is_file($path))
			{
				unlink($path);
			}
		}
	}
	
	protected function dir(): string
	{
		return sys_get_temp_dir();
	}
	
	protected function config(
		array $shield = ['detect' => true],
		array $over = [],
	): ArrayObject
	{
		return new ArrayObject($over + [
			'enabled' => true,
			'url' => 'https://console.invalid',
			'key' => 'test-key',
			'shield' => new ArrayObject($shield + ['dir' => $this->dir()]),
		]);
	}
	
	protected function subject(
		array $shield = ['detect' => true],
		string $prefix = 'install-a',
		int $status = 304,
		array $over = [],
	): Subject
	{
		$subject = new Subject($this->config($shield, $over), $prefix,
			static fn(): array => ['status' => $status, 'headers' => [], 'body' => ''],
			function(string $kind, string $message, array $extra): void
			{
				$this->reports[] = [$kind, $message, $extra];
			});
		$this->paths[] = $subject->file();
		
		return $subject;
	}
	
	protected function rule(
		int $id,
		string $field,
		string $op,
		string $value,
		string $mode = Ruleset::MODE_OBSERVE,
	): array
	{
		return ['id' => $id, 'origin' => 'manual', 'finding' => '', 'cve' => '', 'field' => $field, 'op' => $op, 'value' => $value, 'ci' => true, 'mode' => $mode, 'expires_at' => null];
	}
	
	/**
	 * RULE: the shield runs only with the Sender's direct transport (console
	 * enabled, url, key) AND `shield.detect` on AND `shield.kill` off; an
	 * absent `shield` block is off, so an upgrade never starts pulling on
	 * its own. Falsify: let detect default to on — every php-library app
	 * with a console key starts calling the door the day the lib updates.
	 */
	public function theShieldNeedsTheTransportDetectAndNoKill(): bool
	{
		$enabled = fn(array $shield, array $over = []): bool => (new Subject($this->config($shield, $over)))->isEnabled();
		
		return $enabled(['detect' => true])
			&& $enabled(['detect' => true, 'enforce' => true])
			&& $enabled(['detect' => false]) === false
			&& $enabled([]) === false
			&& $enabled(['detect' => true, 'kill' => true]) === false
			&& $enabled(['enforce' => true]) === false
			&& $enabled(['detect' => true], ['enabled' => false]) === false
			&& $enabled(['detect' => true], ['url' => '']) === false
			&& $enabled(['detect' => true], ['key' => '']) === false
			&& (new Subject(new ArrayObject(['enabled' => true, 'url' => 'https://console.invalid', 'key' => 'k'])))->isEnabled() === false
			&& (new Subject(null))->isEnabled() === false;
	}
	
	/**
	 * RULE: consent reads the three keys as booleans and nothing else (a
	 * string "yes" is not consent); the APCu prefix sits beneath the
	 * install's cache prefix like the rollups'; the durable file is named
	 * by the install and the key, so two installs — or one install with two
	 * keys — never share it, and `shield.dir` decides where it lives.
	 * Falsify: name the file `shield.json` alone — two sites on one box read
	 * each other's rules.
	 */
	public function consentReadsTheThreeSwitchesAndTheStoreIsPerInstall(): bool
	{
		$consent = (new Subject($this->config(['detect' => true, 'enforce' => true, 'kill' => false])))->consent();
		$loose = (new Subject($this->config(['detect' => 'yes', 'enforce' => 1])))->consent();
		$none = (new Subject(new ArrayObject(['enabled' => true])))->consent();
		$a = new Subject($this->config(), 'install-a');
		$b = new Subject($this->config(), 'install-b');
		$otherKey = new Subject($this->config([], ['key' => 'other-key']), 'install-a');
		$elsewhere = new Subject($this->config(['dir' => $this->dir() . '/elsewhere']), 'install-a');
		
		return $consent->detect && $consent->enforce && $consent->kill === false && $consent->active() && $consent->blocks()
			&& $loose->detect === false && $loose->enforce === false
			&& $none->detect === false && $none->enforce === false && $none->kill === false
			&& str_starts_with($a->keyPrefix(), 'install-a') && str_contains($a->keyPrefix(), Subject::PREFIX)
			&& $a->keyPrefix() !== $b->keyPrefix()
			&& $a->file() !== $b->file() && $a->file() !== $otherKey->file()
			&& str_starts_with($a->file(), $this->dir()) && str_contains($a->file(), '/shield-')
			&& str_starts_with($elsewhere->file(), $this->dir() . '/elsewhere/shield-')
			&& str_contains((new Subject($this->config([])))->file(), Subject::DIR) === false;
	}
	
	/**
	 * RULE: hits() hands the Rollup's hook the kernel store's counters of one
	 * minute as `so:<id>` / `sb:<id>` fields — taken once, zero outcomes left
	 * out — and nothing while the shield is not enabled. Skipped without APCu
	 * (the counters live there alone). Falsify: read without taking — every
	 * minute's fragment re-ships the same hits and the overview doubles.
	 */
	public function theHitsLeaveTheStoreOnceAsRollupFields(): bool
	{
		if(function_exists('apcu_enabled') === false || apcu_enabled() === false)
		{
			return true;
		}
		$subject = $this->subject(['detect' => true], 'install-hits-' . uniqid());
		$store = $subject->kernel()->store();
		$minute = 29833333;
		$store->hit($minute, 5, 'observe');
		$store->hit($minute, 5, 'observe');
		$store->hit($minute, 5, 'block');
		$store->hit($minute, 6, 'observe');
		$store->hit($minute + 1, 5, 'observe');
		$taken = $subject->hits($minute);
		ksort($taken);
		$off = new Subject($this->config(['detect' => false]), 'install-hits-off');
		
		return $taken === ['sb:5' => 1, 'so:5' => 2, 'so:6' => 1]
			&& $subject->hits($minute) === []
			&& $subject->hits($minute + 1) === ['so:5' => 1]
			&& $off->hits($minute) === [];
	}
	
	/**
	 * RULE: a request an observe rule matches passes and is reported as
	 * shield_observe through the callable; a PROVEN rule under enforce is
	 * `block`, reported as shield_block, and the plugin's response is a
	 * plain-text 403 naming the rule; without enforce the same rule only
	 * observes; under kill — and without the transport — judge() and pull()
	 * answer `off` and nothing is reported or pulled; the pull runs through
	 * the adapter's transport. Falsify: let
	 * the adapter hand the kernel `enforce` without `detect` — the four
	 * gates collapse to one checkbox.
	 */
	public function aMatchIsReportedAndAProvenRuleUnderEnforceIsA403(): bool
	{
		$rules = [
			$this->rule(2, 'ua', 'prefix', 'python-requests', Ruleset::MODE_PROVEN),
			$this->rule(1, 'uri', 'contains', '/xmlrpc.php'),
		];
		$payload = ['contract' => Ruleset::CONTRACT, 'dialect' => Ruleset::DIALECT, 'project' => 'p', 'rules' => $rules];
		$enforcing = $this->subject(['detect' => true, 'enforce' => true]);
		$enforcing->kernel()->store()->write(Ruleset::fromPayload($payload, time())->toRecord());
		$observing = $this->subject(['detect' => true]);
		$killed = $this->subject(['detect' => true, 'enforce' => true, 'kill' => true]);
		
		$bot = Facts::fromServer(['REQUEST_URI' => '/', 'HTTP_USER_AGENT' => 'python-requests/2.31', 'REMOTE_ADDR' => '203.0.113.9']);
		$probe = Facts::fromServer(['REQUEST_URI' => '/xmlrpc.php?rsd', 'HTTP_USER_AGENT' => 'Mozilla/5.0', 'REMOTE_ADDR' => '203.0.113.9']);
		$plain = Facts::fromServer(['REQUEST_URI' => '/', 'HTTP_USER_AGENT' => 'Mozilla/5.0', 'REMOTE_ADDR' => '203.0.113.9']);
		
		$blocked = $enforcing->judge($bot);
		$observed = $enforcing->judge($probe);
		$passed = $enforcing->judge($plain);
		$softened = $observing->judge($bot);
		$silenced = $killed->judge($bot);
		$response = Plugin::response(2);
		
		return $blocked->outcome === Verdict::BLOCK && $this->reports[0][0] === Verdict::KIND_BLOCK
			&& $this->reports[0][2]['rule'] === 2 && $this->reports[0][2]['mode'] === 'proven'
			&& $observed->outcome === Verdict::OBSERVE && $this->reports[1][0] === Verdict::KIND_OBSERVE && $this->reports[1][2]['rule'] === 1
			&& $passed->isPass() && $passed->reason === 'no match'
			&& $softened->outcome === Verdict::OBSERVE && $this->reports[2][0] === Verdict::KIND_OBSERVE
			&& $silenced->isPass() && $silenced->reason === 'off' && count($this->reports) === 3
			&& $killed->pull() === Kernel::PULL_OFF
			// not enabled for want of a key: the adapter answers off before the kernel could try the transport
			&& $this->subject(['detect' => true], 'install-d', 304, ['key' => ''])->pull() === Kernel::PULL_OFF
			&& $enforcing->pull() === Kernel::PULL_SKIPPED
			&& $this->subject(['detect' => true], 'install-c')->pull() === Kernel::PULL_FAILED
			&& $response->getHttpCode() === 403
			&& ($response->getHeaders()[Plugin::HEADER_RULE]['value'] ?? null) === '2'
			&& Subject::isAuthed(null) === null;
	}
}
