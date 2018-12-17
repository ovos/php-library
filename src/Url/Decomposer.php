<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Locale;
use function Ovos\app;

/**
 * Url
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Decomposer
{
	/**
	 * @var bool
	 */
	protected $_system = true;

	/**
	 * @var Locale
	 */
	protected $_locale;

	/**
	 * @var string
	 */
	protected $_url;

	/**
	 * Construct
	 *
	 * @param string[] $url
	 */
	public function __construct(...$url)
	{
		$this->setUrl(implode('/', $url));
	}

	/**
	 * @param string $url
	 *
	 * @return $this
	 */
	public function setUrl($url): self
	{
		$this->_url = $url;

		return $this;
	}

	/**
	 * @param bool $system
	 *
	 * @return $this
	 */
	public function setSystem($system): self
	{
		$this->_system = $system;

		return $this;
	}

	/**
	 * @param Locale $locale
	 *
	 * @return $this
	 */
	public function setLocale($locale): self
	{
		$this->_locale = $locale;

		return $this;
	}

	/**
	 * @return null|Locale
	 */
	public function getLocale(): ?Locale
	{
		return $this->_locale;
	}

	/**
	 * @return string
	 */
	public function getUrl(): string
	{
		$url = $this->_url;

		if($this->_system)
		{
			$locale = $this->_locale;
			if($this->_locale === null)
			{
				$locale = app()->getRequest()->getLocale();
			}

			if($locale->isDefault() === false)
			{
				if(strtok($url, '/') !== $locale->getSymbol())
				{
					$url = $locale->getSymbol() . '/' . $url;
				}
			}

			$url = SYSTEM_DIR . $url;
		}

		return $url;
	}

	/**
	 * @return string
	 */
	public function __toString(): string
	{
		return $this->getUrl();
	}
}
