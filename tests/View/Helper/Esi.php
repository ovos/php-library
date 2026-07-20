<?php
declare(strict_types=1);

namespace Tests\View\Helper;

use Ovos\Application;
use Ovos\Test;
use Ovos\Test\Internal;
use Ovos\View\Helper\Esi as EsiHelper;
use Override;

use function file_put_contents;
use function is_file;
use function str_contains;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * Esi view helper: surrogate negotiation — <esi:include> emission behind a
 * capable surrogate, inline fragment rendering without one.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Esi extends Test
{
	/**
	 * @var string[] absolute paths of fixture view scripts to remove
	 */
	protected array $files = [];
	
	public function rendersClosureInlineWithoutSurrogate()
	{
		$this->withoutSurrogate();
		
		$html = (new EsiHelper)->esi('/fragment/boot', fn() => '<p>inline</p>');
		
		return $html === '<p>inline</p>'
			&& $this->surrogateControlHeader() === null;
	}
	
	public function rendersViewScriptInlineWithoutSurrogate()
	{
		$this->withoutSurrogate();
		$script = $this->makeFragmentScript('<b><?= $this->escape($this->word); ?></b>');
		
		$html = (new EsiHelper)->esi('/fragment/boot', $script, ['word' => 'a&b']);
		
		return $html === '<b>a&amp;b</b>';
	}
	
	public function emitsIncludeTagForSurrogate()
	{
		$this->withSurrogate();
		
		$html = (new EsiHelper)->esi('/fragment/boot', fn() => 'never rendered');
		
		return $html === '<esi:include src="/fragment/boot" onerror="continue"/>';
	}
	
	public function stampsSurrogateControlOnTheResponse()
	{
		$this->withSurrogate();
		
		(new EsiHelper)->esi('/fragment/boot', fn() => '');
		
		return $this->surrogateControlHeader() === 'content="ESI/1.0"';
	}
	
	public function escapesTheSrcAttribute()
	{
		$this->withSurrogate();
		
		$html = (new EsiHelper)->esi('/fragment/boot?a=1&b="x"', fn() => '');
		
		return str_contains($html, 'src="/fragment/boot?a=1&amp;b=&quot;x&quot;"');
	}
	
	public function omitsOnerrorWhenErrorsMatter()
	{
		$this->withSurrogate();
		
		$html = (new EsiHelper)->esi('/fragment/boot', fn() => '', ignoreErrors: false);
		
		return $html === '<esi:include src="/fragment/boot"/>';
	}
	
	/**
	 * A Surrogate-Capability without the ESI/1.0 token is not an ESI
	 * surrogate — the fragment must render inline
	 */
	public function ignoresForeignCapabilityTokens()
	{
		$_SERVER['HTTP_SURROGATE_CAPABILITY'] = 'key=OTHER/2.0';
		
		$html = (new EsiHelper)->esi('/fragment/boot', fn() => '<p>inline</p>');
		
		return $html === '<p>inline</p>'
			&& (new EsiHelper)->hasSurrogate() === false;
	}
	
	#[Internal]
	protected function withSurrogate(): void
	{
		$_SERVER['HTTP_SURROGATE_CAPABILITY'] = 'key=ESI/1.0';
	}
	
	#[Internal]
	protected function withoutSurrogate(): void
	{
		unset($_SERVER['HTTP_SURROGATE_CAPABILITY']);
	}
	
	/**
	 * The Surrogate-Control header value on the app response, null when absent
	 */
	#[Internal]
	protected function surrogateControlHeader(): ?string
	{
		$headers = $this->container->get(Application::class)
			->getResponse()
			->getHeaders();
		
		return $headers['Surrogate-Control']['value'] ?? null;
	}
	
	/**
	 * A throwaway .phtml fixture; returns its absolute path — View::render
	 * includes the path directly
	 */
	#[Internal]
	protected function makeFragmentScript(
		string $markup,
	): string
	{
		$script = sys_get_temp_dir() . DIRECTORY_SEPARATOR
			. 'tests-esi-' . uniqid() . '.phtml';
		file_put_contents($script, $markup);
		
		$this->files[] = $script;
		
		return $script;
	}
	
	#[Internal]
	#[Override]
	public function deconstruct(): void
	{
		unset($_SERVER['HTTP_SURROGATE_CAPABILITY']);
		
		$this->container->get(Application::class)
			->getResponse()
			->clearHeader('Surrogate-Control');
		
		foreach($this->files as $file)
		{
			if(is_file($file))
			{
				unlink($file);
			}
		}
	}
}
