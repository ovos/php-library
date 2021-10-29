<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Form\Element;
use Ovos\View\Helper;
use function call_user_func_array;
use function method_exists;
use function ob_start;
use function ob_get_clean;
use function array_key_exists;
use function class_exists;

/**
 * View
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 *
 * @method static Helper\Asset asset
 * @method static Helper\Benchmark benchmark
 * @method static Helper\Body body
 * @method static Helper\Elements elements
 * @method static Helper\FormElement formElement(Element $element)
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
	protected Application $_app;

	/**
	 * @var ?string
	 */
	protected ?string $_viewScriptFile;

	/**
	 * @var array
	 */
	protected array $_vars = [];

	/**
	 * @var array
	 */
	protected static array $_helpers = [];

	/**
	 * @param ?string $viewScriptFile
	 * @param array $vars
	 */
	public function __construct(?string $viewScriptFile = null, array $vars = [])
	{
		$this->_viewScriptFile = $viewScriptFile;

		$this->_app = app();
		$this->app = $this->_app;
		$this->interface = $this->_app->getInterface();
		$this->config = $this->_app->getConfig();
		$this->request = $this->_app->getRequest();
		$this->url = $this->_app->getRequest()->getUrl();
		$this->controller = $this->_app->getRequest()->getController();
		$this->controllerInstance = $this->_app->getRequest()->getControllerInstance();
		$this->action = $this->_app->getRequest()->getAction();
		$this->locale = $this->_app->getRequest()->getLocale();
		$this->client = Client::class;

		// assign additional variables
		$this->setMultiple($vars);
	}

	/**
	 * @param string $name
	 *
	 * @return mixed
	 */
	public function __get(string $name): mixed
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
	public function __set(string $name, mixed $value): void
	{
		$this->_vars[$name] = $value;
	}

	/**
	 * @param string $name
	 */
	public function __unset(string $name): void
	{
		unset($this->_vars[$name]);
	}

	/**
	 * @param string $name
	 * @param array $arguments
	 *
	 * @return mixed
	 */
	public function __call(string $name, array $arguments): mixed
	{
		return self::__callStatic($name, $arguments);
	}

	/**
	 * @param string $name
	 * @param array $arguments
	 *
	 * @return mixed
	 */
	public static function __callStatic(string $name, array $arguments): mixed
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
			return call_user_func_array([$instance, $name], $arguments);
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
	 * @param mixed $value The output to escape.
	 *
	 * @return mixed The escaped value.
	 */
	public function escape(mixed $value): mixed
	{
		if($value === null)
		{
			return $value;
		}
		
		if(!is_string($value))
		{
			return $value;
		}
		
		if(is_numeric($value)) // a numeric string
		{
			return $value;
		}
		
		return Strings::escapeForHtml($value);
	}

	/**
	 * @param ?string $viewScriptFile (optional)
	 * @param array $variables (optional)
	 *
	 * @return string
	 *
	 * @throws Exception
	 */
	public function render(?string $viewScriptFile = null, array $variables = []): string
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
	public function setMultiple(array $variables): void
	{
		foreach($variables as $name => $value)
		{
			$this->__set($name, $value);
		}
	}

	/**
	 * @return array
	 */
	public function getVars(): array
	{
		return $this->_vars;
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
