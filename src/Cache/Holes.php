<?php
declare(strict_types=1);

namespace Ovos\Cache;

use Ovos\Exception;
use Closure;

use function array_key_exists;
use function count;
use function preg_match;
use function preg_split;
use function str_contains;

use const PREG_SPLIT_DELIM_CAPTURE;

/**
 * Holes
 *
 * The request-scoped registry of late-bound page fragments ("holes"). A
 * cached page stores a SENTINEL where per-request output belongs (a CSRF
 * token, a user greeting), and the provider registered under that name
 * fills it on EVERY serve - hit and miss alike. Cache the shared shell
 * once, recompute only the holes: a personalised page rides a common
 * cache entry instead of exploding into per-user copies.
 *
 * A captured body is stored PRE-SPLIT at its sentinels (segments +
 * hole names), so serving interleaves stored strings with resolved
 * values - it never parses or scans the body again.
 *
 * On a cache HIT the page's templates never run, so a provider declared
 * inline in a template does not exist yet. A hole used on a cached page
 * must therefore also be registered by code that runs on every request
 * (a plugin's preDispatch, a bootstrap) via provide(); the template's
 * inline closure covers the capturing miss and documents the hole where
 * it is used. A hit that cannot fill every hole is NOT served - it falls
 * back to a miss and leaves a dev-console note.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
final class Holes
{
	/**
	 * The ASCII record separator - a byte that never occurs in HTML
	 */
	public const string SENTINEL = "\x1E";
	
	protected const string NAME_PATTERN = '~^[A-Za-z0-9._-]+$~';
	
	/**
	 * @var array<string, Closure>
	 */
	protected array $providers = [];
	
	/**
	 * Resolved values, memoized per request - a hole appearing several
	 * times on a page runs its provider once
	 *
	 * @var array<string, string>
	 */
	protected array $resolved = [];
	
	/**
	 * Names that had no provider when resolved
	 *
	 * @var string[]
	 */
	protected array $missed = [];
	
	protected bool $capturing = false;
	
	public function provide(
		string $name,
		Closure $provider,
	): static
	{
		$this->assertName($name);
		
		$this->providers[$name] = $provider;
		unset($this->resolved[$name]);
		
		return $this;
	}
	
	public function has(
		string $name,
	): bool
	{
		return isset($this->providers[$name]);
	}
	
	/**
	 * The hole's value for THIS request; an unprovided name resolves to
	 * an empty string and is recorded in missed()
	 */
	public function resolve(
		string $name,
	): string
	{
		if(array_key_exists($name, $this->resolved) === true)
		{
			return $this->resolved[$name];
		}
		
		if(isset($this->providers[$name]) === false)
		{
			$this->missed[] = $name;
			
			return $this->resolved[$name] = '';
		}
		
		return $this->resolved[$name] = (string)($this->providers[$name])();
	}
	
	/**
	 * @return string[]
	 */
	public function missed(): array
	{
		return $this->missed;
	}
	
	/**
	 * Whether a capturing page render is in progress - View::hole() then
	 * emits sentinels instead of values
	 */
	public function capturing(
		bool $capturing,
	): static
	{
		$this->capturing = $capturing;
		
		return $this;
	}
	
	public function isCapturing(): bool
	{
		return $this->capturing;
	}
	
	/**
	 * The sentinel a capturing render emits in place of the hole's value
	 */
	public function placeholder(
		string $name,
	): string
	{
		$this->assertName($name);
		
		return self::SENTINEL . $name . self::SENTINEL;
	}
	
	public function contains(
		string $html,
	): bool
	{
		return str_contains($html, self::SENTINEL);
	}
	
	/**
	 * Split a captured body at its sentinels. The parts interleave as
	 * segments[0] hole[0] segments[1] hole[1] … segments[n] - one more
	 * segment than holes, empty-string segments included, so assemble()
	 * is a blind zip with no parsing.
	 *
	 * @return array{segments: string[], holes: string[]}
	 */
	public function split(
		string $html,
	): array
	{
		$parts = preg_split(
			'~' . self::SENTINEL . '([A-Za-z0-9._-]+)' . self::SENTINEL . '~',
			$html,
			flags: PREG_SPLIT_DELIM_CAPTURE,
		);
		
		$segments = [];
		$holes = [];
		foreach($parts as $index => $part)
		{
			if($index % 2 === 0)
			{
				$segments[] = $part;
			}
			else
			{
				$holes[] = $part;
			}
		}
		
		return [
			'segments' => $segments,
			'holes' => $holes,
		];
	}
	
	/**
	 * Whether every hole can be filled right now - a hit must not serve
	 * a page with unfillable holes
	 *
	 * @param string[] $holes
	 */
	public function canResolveAll(
		array $holes,
	): bool
	{
		foreach($holes as $name)
		{
			if(isset($this->providers[$name]) === false)
			{
				return false;
			}
		}
		
		return true;
	}
	
	/**
	 * Interleave stored segments with freshly resolved hole values
	 *
	 * @param string[] $segments
	 * @param string[] $holes
	 */
	public function assemble(
		array $segments,
		array $holes,
	): string
	{
		$body = $segments[0] ?? '';
		
		$count = count($holes);
		for($index = 0; $index < $count; $index++)
		{
			$body.= $this->resolve($holes[$index])
				. ($segments[$index + 1] ?? '');
		}
		
		return $body;
	}
	
	/**
	 * Resolve any sentinels inside a standalone string - for cached
	 * FRAGMENTS captured during a capturing page render and later served
	 * into a non-captured page
	 */
	public function fill(
		string $html,
	): string
	{
		if($this->contains($html) === false)
		{
			return $html;
		}
		
		$split = $this->split($html);
		
		return $this->assemble($split['segments'], $split['holes']);
	}
	
	protected function assertName(
		string $name,
	): void
	{
		if(preg_match(self::NAME_PATTERN, $name) !== 1)
		{
			throw new Exception('Invalid hole name "%s".', $name);
		}
	}
}
