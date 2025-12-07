<?php
declare(strict_types=1);

namespace Ovos\View\Helper\Placeholders;

use Ovos\View;

use function array_search;
use function ob_start;
use function ob_get_clean;
use function sprintf;

/**
 * Placeholder
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Placeholder
{
	// Placements
	public const string PLACEMENT_REPLACE = 'replace';
	public const string PLACEMENT_PREPEND = 'prepend';
	public const string PLACEMENT_APPEND = 'append';
	
	protected null|string|bool|int $value = null;
	
	protected array $scripts = [];
	
	public function set(
		null|string|bool|int $value,
		string $placement = self::PLACEMENT_REPLACE,
	): static
	{
		switch($placement)
		{
			case self::PLACEMENT_REPLACE:
				$this->value = $value;
				break;
			case self::PLACEMENT_PREPEND:
				$this->value = $value . $this->value;
				break;
			case self::PLACEMENT_APPEND:
				$this->value.= $value;
				break;
		}
		
		return $this;
	}
	
	public function prepend(
		null|string|bool|int $value,
	): static
	{
		$this->set($value, self::PLACEMENT_PREPEND);
		
		return $this;
	}
	
	public function append(
		null|string|bool|int $value,
	): static
	{
		$this->set($value, self::PLACEMENT_APPEND);
		
		return $this;
	}
	
	public function captureStart(): void
	{
		ob_start();
	}
	
	public function captureEnd(
		string $placement = self::PLACEMENT_APPEND,
	): void
	{
		$this->set(ob_get_clean(), $placement);
	}
	
	public function includeScript(
		string $script,
		string $placement = self::PLACEMENT_APPEND,
		string $template = '<script type="text/javascript" src="%s"></script>'
			. PHP_EOL,
		bool $asset = false,
	): static
	{
		if($asset)
		{
			$script = View::asset($script);
		}
		
		if(in_array($script, $this->scripts, true))
		{
			return $this;
		}
		
		$this->set(sprintf($template, $script), $placement);
		$this->scripts[] = $script;
		
		return $this;
	}
	
	public function getValue(
	): null|string|bool|int
	{
		return $this->value;
	}
	
	/**
	 * @return string
	 */
	public function __toString(): string
	{
		return (string)$this->getValue();
	}
}
