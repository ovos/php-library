<?php
declare(strict_types=1);

namespace Ovos\View\Helper\Placeholders;

use Ovos\View;

use function ob_start;
use function ob_get_clean;
use function sprintf;
use function array_search;

/**
 * Placeholder
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Placeholder
{
	/**#@+
	 * Placements
	 */
	public const string PLACEMENT_REPLACE = 'replace';
	public const string PLACEMENT_PREPEND = 'prepend';
	public const string PLACEMENT_APPEND = 'append';
	/**#@-*/

	/**
	 * @var null|string|bool|int
	 */
	protected null|string|bool|int $_value = null;
	
	/**
	 * @var array
	 */
	protected array $_scripts = [];

	/**
	 * @param null|string|bool|int $value
	 * @param string $placement
	 *
	 * @return self
	 */
	public function set(
		null|string|bool|int $value,
		string $placement = self::PLACEMENT_REPLACE,
	): self
	{
		switch($placement)
		{
			case self::PLACEMENT_REPLACE:
				$this->_value = $value;
				break;
			case self::PLACEMENT_PREPEND:
				$this->_value = $value . $this->_value;
				break;
			case self::PLACEMENT_APPEND:
				$this->_value.= $value;
				break;
		}
		
		return $this;
	}
	
	/**
	 * @param null|string|bool|int $value
	 *
	 * @return self
	 */
	public function prepend(
		null|string|bool|int $value
	): self
	{
		$this->set($value, self::PLACEMENT_PREPEND);
		
		return $this;
	}
	
	/**
	 * @param null|string|bool|int $value
	 *
	 * @return self
	 */
	public function append(
		null|string|bool|int $value
	): self
	{
		$this->set($value, self::PLACEMENT_APPEND);
		
		return $this;
	}
	
	/**
	 */
	public function captureStart(): void
	{
		ob_start();
	}
	
	/**
	 * @param string $placement
	 */
	public function captureEnd(
		string $placement = self::PLACEMENT_APPEND
	): void
	{
		$this->set(ob_get_clean(), $placement);
	}
	
	/**
	 * @param string $script
	 * @param string $placement
	 * @param string $template
	 * @param bool $asset
	 * 
	 * @return self
	 */
	public function includeScript(
		string $script,
		string $placement = self::PLACEMENT_APPEND,
		string $template = '<script type="text/javascript" src="%s"></script>' . PHP_EOL,
		bool $asset = false,
	): self
	{
		if($asset)
		{
			$script = View::asset($script);
		}
		
		if(in_array($script, $this->_scripts, true))
		{
			return $this;
		}
		
		$this->set(sprintf($template, $script), $placement);
		$this->_scripts[] = $script;
		
		return $this;
	}
	
	/**
	 * @return null|string|bool|int
	 */
	public function getValue(): null|string|bool|int
	{
		return $this->_value;
	}
	
	/**
	 * @return string
	 */
	public function __toString(): string
	{
		return (string)$this->getValue();
	}
}
