<?php
declare(strict_types=1);

namespace Ovos\Plugins;

use Ovos\Controller\Plugin;
use Ovos\Response;
use Ovos\View;

use function get_class;

/**
 * Layout
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Layout extends Plugin
{
	/**
	 * @var string
	 */
	public const string SYMBOL = 'layout';
	
	/**
	 * @var string
	 */
	public const string CONTENT_PLACEHOLDER = 'content';
	
	/**
	 * @var View
	 */
	protected View $_layout;
	
	/**
	 * Automatically create these placeholders
	 *
	 * @var array
	 */
	protected array $_placeholders = [];
	
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
		
		// automatically create these placeholders
		foreach($this->_placeholders as $placeholder)
		{
			$this->_layout::placeholders()->{$placeholder};
		}
	}
	
	/**
	 * @return View
	 */
	public function getLayout(): View
	{
		return $this->_layout;
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
			$this->_layout->$placeholder = $value;
		}
		
		$response = $this->_app->getResponse();
		if($response instanceof Response
			&& get_class($response) === Response\Html::class)
		{
			/** @var Response\Html $response */
			$this->_layout->{self::CONTENT_PLACEHOLDER}.= $response->get();
			$response->set($this->_layout->__toString());
		}
	}
}
