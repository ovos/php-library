<?php
declare(strict_types=1);

namespace Ovos\Plugins;

use Ovos\Application;
use Ovos\Controller\Plugin;
use Ovos\Response;
use Ovos\View;
use function Ovos\app;

/**
 * Layout
 *
 * @package Plugins
 * @author Marcin Gil <mg@ovos.at>
 */
class Layout extends Plugin
{
	/**
	 * @var string
	 */
	public const SYMBOL = 'layout';

	/**
	 * @var string
	 */
	public const CONTENT_PLACEHOLDER = 'content';

	/**
	 * @var View
	 */
	protected $_layout;

	/**
	 * @param string $layout
	 */
	public function __construct(string $layout)
	{
		parent::__construct();

		// disable on XMLHttpRequest
		if($this->_request->isXmlHttpRequest())
		{
			$this->disable();
			return;
		}

		$this->_layout = new View\Layout($layout);
		$this->_layout::placeholders()->clear();
	}

	/**
	 * @return View
	 */
	public function getLayout(): View
	{
		return $this->_layout;
	}

	/**
	 * @return string
	 */
	public static function getSymbol(): string
	{
		return self::SYMBOL;
	}

	/**
	 * @return void
	 */
	public function preDispatch(): void
	{
	}

	/**
	 * @return void
	 */
	public function postDispatch(): void
	{
		foreach($this->_layout::placeholders()->toArray() as $placeholder => $value)
		{
			$this->_layout->$placeholder = (string)$value; // calls __toString on widgets
		}

		$response = $this->_app->getResponse();
		if($response instanceof Response
			&& \get_class($response) === Response\Html::class)
		{
			/** @var Response\Html $response */
			$this->_layout->{self::CONTENT_PLACEHOLDER} = $response->get();
			$response->set($this->_layout->__toString());
		}
	}
}