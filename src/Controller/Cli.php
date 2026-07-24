<?php
declare(strict_types=1);

namespace Ovos\Controller;

use Ovos\Controller;
use Ovos\Exception\ForbiddenException;
use Ovos\Response;
use Ovos\Terminal;
use Override;

use function array_merge;
use function count;
use function date;
use function getmypid;
use function in_array;
use function memory_get_usage;
use function round;
use function sprintf;
use function str_contains;

/**
 * Cli
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Cli extends Controller
{
	use Controller\Traits\Cli;
	
	protected bool $allowHttpAccess = false;
	
	/**
	 * Allows accessing specified CLI methods via HTTP
	 */
	protected array $httpActions = [];
	
	/**
	 * Process id
	 */
	protected ?int $pid = null;
	
	/**
	 * preDispatch
	 */
	#[Override]
	public function preDispatch(
		array $actionParams,
	): void
	{
		// decide HTTP access BEFORE running plugins: for a forbidden
		// CLI-over-HTTP request a plugin (e.g. Auth) must not get to install a
		// login redirect as the response first. A leftover Redirect response
		// short-circuits the error page in Application::handleShutdown() (which
		// only re-renders Html/Cli responses through System\Events), so the
		// client would receive the redirect URL as the body under a 500 instead
		// of a clean 403.
		if($this->getRequest()->isCli() === false
			&& $this->isAllowedHttpAccess() === false
			&& in_array($this->getRequest()->getAction(),
				$this->httpActions,
				true,
			) === false)
		{
			throw new ForbiddenException('Forbidden.');
		}
		
		$this->preDispatchPlugins();
	}
	
	public function getPid(): int
	{
		if($this->pid === null)
		{
			$this->pid = getmypid();
		}
		
		return $this->pid;
	}
	
	public function setAllowHttpAccess(
		bool $allowHttpAccess,
	): static
	{
		$this->allowHttpAccess = $allowHttpAccess;
		
		return $this;
	}
	
	public function isAllowedHttpAccess(): bool
	{
		return $this->allowHttpAccess;
	}
	
	public function addHttpAction(
		string $action,
	): static
	{
		$this->httpActions[] = $action;
		
		return $this;
	}
	
	public function addHttpActions(
		array $actions,
	): static
	{
		$this->httpActions = array_merge($this->httpActions, $actions);
		
		return $this;
	}
	
	public function getHttpActions(): array
	{
		return $this->httpActions;
	}
	
	/**
	 * Returns memory usage in MB
	 */
	public function getMemoryUsageMb(): float
	{
		return round(
			memory_get_usage(false) / (1024 * 1024),
			2,
		);
	}
	
	/**
	 * Log messages
	 */
	public function log(
		...$message,
	): static
	{
		if(count($message))
		{
			$message = sprintf(...$message);
			
			// a multiline block (a Terminal\Table, a trace) starts on its own
			// line: glued to the prefix, its first row would sit ~30 columns
			// right of the block's remaining rows
			if(str_contains($message, "\n"))
			{
				$message = PHP_EOL . $message;
			}
		}
		
		Terminal::output('<darkgray>[' . $this->getPid() . '] '
			. '<purple>' . date('Y-m-d H:i:s') . ': '
			. '<reset>' . $message . '<reset>' . PHP_EOL,
			markup: ($response = $this->app->getResponse())
				&& $response instanceof Response\Cli
				&& $response->getColoredOutput()
		);
		
		return $this;
	}
}
