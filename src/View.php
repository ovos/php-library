<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\View\Helper;
use Ovos\Translatable;
use ReflectionClass;

/**
 * View
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 *
 * @method static Helper\Benchmark benchmark
 * @method static Helper\Body body
 * @method static Helper\Elements elements
 * @method static Helper\Queries queries
 * @method static Helper\Messages messages
 * @method static Helper\Placeholders placeholders
 * @method static Helper\Title title
 * @method static Helper\Url url
 */
class View
{
	use Translatable;

	/**
	 * @var string
	 */
	public const SUFFIX = '.phtml';

	/**
	 * @var Application
	 */
	protected $_app;

	/**
	 * @var string
	 */
	protected $_viewScriptFile;

	/**
	 * @var array
	 */
	protected $_vars = [];

	/**
	 * @var array
	 */
	protected static $_helpers = [];

	/**
	 * @param string $viewScriptFile
	 */
	public function __construct(string $viewScriptFile = null, array $variables = [])
	{
		$this->_viewScriptFile = $viewScriptFile;

		$this->_app = app();
		$this->app = $this->_app;
		$this->interface = $this->_app->getInterface();
		$this->config = $this->_app->getConfig();
		$this->request = $this->_app->getRequest();
		$this->url = $this->_app->getRequest()->getUrl();
		$this->controller = $this->_app->getRequest()->getController();
		$this->action = $this->_app->getRequest()->getAction();
		$this->locale = $this->_app->getRequest()->getLocale();
		$this->client = Client::class;

		// assign additional variables
		$this->setMultiple($variables);
	}

	/**
	 * @param string $name
	 *
	 * @return mixed
	 */
	public function __get(string $name)
	{
		if($this->__isset($name) === false)
		{
			return null;
		}

		return $this->_vars[$name];
	}

	/**
	 * @param string $name
	 *
	 * @return bool
	 */
	public function __isset(string $name): bool
	{
		return array_key_exists($name, $this->_vars);
	}

	/**
	 * @param string $name
	 * @param mixed $value
	 */
	public function __set(string $name, $value)
	{
		$this->_vars[$name] = $value;
	}

	/**
	 * @param string $name
	 *
	 * @return $this
	 */
	public function __unset(string $name): self
	{
		unset($this->_vars[$name]);

		return $this;
	}

	/**
	 * @param string $name
	 * @param array $arguments
	 *
	 * @return mixed
	 */
	public function __call(string $name, array $arguments)
	{
		return self::__callStatic($name, $arguments);
	}

	/**
	 * @param string $name
	 * @param array $arguments
	 *
	 * @return mixed
	 */
	public static function __callStatic(string $name, array $arguments)
	{
		if(!isset(self::$_helpers[$name]))
		{
			$helper = self::getHelperClass($name);
			$instance = new $helper;

			//$reflector = new ReflectionClass($helper);
			//$instance = $reflector->newInstanceArgs($arguments);

			self::$_helpers[$name] = $instance;
		}

		$instance = self::$_helpers[$name];

		if(method_exists($instance, $name))
		{
			return \call_user_func_array([$instance, $name], $arguments);
		}

		return $instance;
	}

	/**
	 * @param string $name
	 *
	 * @return string
	 */
	public static function getHelperClass(string $name): string
	{
		$systemConfig = app()->getConfig()->system;
		$name = ucfirst($name);
	
		/** @var ArrayObject $viewHelpers */
		if(($namespaces = $systemConfig->get('view_helpers.namespaces')))
		{
			foreach($namespaces as $namespace)
			{
				// checks in classmap on production / tries to load the file on development
				if(class_exists($namespace . $name))
				{
					return $namespace . $name;
				}
			}
		}

		return 'Ovos\View\Helper\\'. $name;
	}

	/**
	 * Escapes a value for output in a view script.
	 *
	 * @param null|mixed $value The output to escape.
	 *
	 * @return null|mixed The escaped value.
	 */
	public function escape($value)
	{
		if($value === null)
		{
			return $value;
		}
		
		if(is_numeric($value))
		{
			return $value;
		}
		
		return Strings::escapeForHtml($value);
	}

	/**
	 * @param string $viewScriptFile (optional)
	 * @param array $variables (optional)
	 *
	 * @return string
	 *
	 * @throws Exception
	 */
	public function render(string $viewScriptFile = null, array $variables = []): string
	{
		if($viewScriptFile === null)
		{
			$viewScriptFile = $this->_viewScriptFile;
		}

		if($viewScriptFile === null)
		{
			throw new Exception('View script cannot be null.');
		}

		// assign additional variables
		$this->setMultiple($variables);

		ob_start();
		include $viewScriptFile;

		return ob_get_clean();
	}
	
	/**
	 * @param array $variables
	 */
	public function setMultiple($variables)
	{
		foreach($variables as $name => $value)
		{
			$this->__set($name, $value);
		}
	}

	/**
	 * @return string
	 *
	 * @throws Exception
	 */
	public function __toString(): string
	{
		return $this->render();
	}
}
