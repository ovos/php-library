<?php
declare(strict_types=1);

namespace Ovos\Controller;

use Ovos\Controller;
use Ovos\Exception;
use Ovos\Response;
use Ovos\Terminal;
use Override;

use function in_array;
use function array_merge;
use function sprintf;
use function round;
use function memory_get_usage;
use function count;
use function getmypid;
use function date;

/**
 * Cli
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Cli extends Controller
{
	use Controller\Traits\Cli;
	
	/**
	 * @var bool
	 */
	protected bool $_allowHttpAccess = false;
	
	/**
	 * Allows accessing specified CLI methods via HTTP
	 *
	 * @var array
	 */
	protected array $_httpActions = [];
	
	/**
	 * Process id
	 *
	 * @var ?int
	 */
	protected ?int $_pid = null;
	
	/**
	 * preDispatch
	 * 
	 * @param array $actionParams
	 */
	#[Override]
	public function preDispatch(array $actionParams): void
	{
		$this->preDispatchPlugins();
		
		if($this->getRequest()->isCli() === true)
		{
			return;
		}
		
		if($this->isAllowedHttpAccess())
		{
			return;
		}
		
		if(in_array($this->getRequest()->getAction(), $this->_httpActions, true) === true)
		{
			return;
		}
		
		throw new Exception('Forbidden.');
	}
	
	/**
	 * @return int
	 */
	public function getPid(): int
	{
		if($this->_pid === null)
		{
			$this->_pid = getmypid();
		}
		
		return $this->_pid;
	}
	
	/**
	 * @param bool $allowHttpAccess
	 *
	 * @return static
	 */
	public function setAllowHttpAccess(bool $allowHttpAccess): static
	{
		$this->_allowHttpAccess = $allowHttpAccess;
		
		return $this;
	}
	
	/**
	 * @return bool
	 */
	public function isAllowedHttpAccess(): bool
	{
		return $this->_allowHttpAccess;
	}
	
	/**
	 * @param string $action
	 *
	 * @return static
	 */
	public function addHttpAction(string $action): static
	{
		$this->_httpActions[] = $action;
		
		return $this;
	}
	
	/**
	 * @param array $actions
	 *
	 * @return static
	 */
	public function addHttpActions(array $actions): static
	{
		$this->_httpActions = array_merge($this->_httpActions, $actions);
		
		return $this;
	}
	
	/**
	 * @return array
	 */
	public function getHttpActions(): array
	{
		return $this->_httpActions;
	}
	
	/**
	 * Returns memory usage in MB
	 *
	 * @return float
	 */
	public function getMemoryUsageMb(): float
	{
		return round(memory_get_usage(false) / (1024 * 1024), 2);
	}
	
	/**
	 * Log messages
	 *
	 * @param string ...$message,... params for sprintf
	 *
	 * @return static
	 */
	public function log(...$message): static
	{
		if(count($message))
		{
			$message = sprintf(...$message);
		}
		var_dump($response->getColoredOutput());
		Terminal::output('<darkgray>[' . $this->getPid() . '] '
			. '<purple>' . date('Y-m-d H:i:s') . ': '
			. '<reset>' . $message . '<reset>' . PHP_EOL,
			markup: ($response = $this->_app->getResponse())
				&& $response instanceof Response\Cli
				&& $response->getColoredOutput()
		);
		
		return $this;
	}
}
