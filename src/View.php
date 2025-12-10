<?php
declare(strict_types=1);

namespace Ovos;

use Ovos\Form\Element;
use Ovos\View\Helper;
use Ovos\Service\Events;
use Throwable;

use function array_key_exists;
use function call_user_func_array;
use function class_exists;
use function method_exists;
use function ob_get_clean;
use function ob_start;

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
 * @method static Helper\Email email
 */
class View
{
	use Translatable;
	
	public const string SUFFIX = '.phtml';
	
	protected Container $container;
	
	protected Application $app;
	
	protected Request $request;
	
	protected Events $eventsService;
	
	protected ?string $viewScriptFile;
	
	protected array $vars = [];
	
	protected static array $helpers = [];
	
	public function __construct(
		?string $viewScriptFile = null,
		array $vars = [],
	)
	{
		$this->viewScriptFile = $viewScriptFile;
		
		$this->container = container();
		$this->app = $this->container
			->getClass(Application::class);
		$this->request = $this->app->getRequest();
		$this->eventsService = $this->container->get(Events::SYMBOL);
		
		$this->app = $this->app;
		$this->interface = $this->app->getInterface();
		$this->config = $this->app->getConfig();
		$this->request = $this->app->getRequest();
		
		$this->url = $this->request->getUrl();
		$this->controller = $this->request->getController();
		$this->controllerInstance = $this->request->getControllerInstance();
		$this->action = $this->request->getAction();
		$this->locale = $this->request->getLocale();
		$this->client = Client::class;
		
		// assign additional variables
		$this->setMultiple($vars);
	}
	
	public function __get(
		string $name,
	): mixed
	{
		if($this->__isset($name) === false)
		{
			return null;
		}
		
		return $this->vars[$name];
	}
	
	public function __isset(
		string $name,
	): bool
	{
		return array_key_exists($name, $this->vars);
	}
	
	public function __set(
		string $name,
		mixed $value,
	): void
	{
		$this->vars[$name] = $value;
	}
	
	public function __unset(
		string $name,
	): void
	{
		unset($this->vars[$name]);
	}
	
	public function __call(
		string $name,
		array $arguments,
	): mixed
	{
		return self::__callStatic($name, $arguments);
	}
	
	public static function __callStatic(
		string $name,
		array $arguments,
	): mixed
	{
		if(isset(self::$helpers[$name]) === false)
		{
			$helper = self::getHelperClass($name);
			$instance = container()->getClass($helper, $helper);
			
			self::$helpers[$name] = $instance;
		}
		
		$instance = self::$helpers[$name];
		
		if(method_exists($instance, $name))
		{
			return call_user_func_array([$instance, $name], $arguments);
		}
		
		return $instance;
	}
	
	public static function getHelperClass(
		string $name,
	): string
	{
		$systemConfig = container()
			->get(Application::CONTAINER_KEY_CONFIG)
			->system;
		$name = ucfirst($name);
		
		/** @var ArrayObject $systemConfig */
		if($namespaces = $systemConfig->getPath(['view_helpers', 'namespaces']))
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
		
		return 'Ovos\View\Helper\\' . $name;
	}
	
	/**
	 * Escapes a value for output in a view script.
	 */
	public function escape(
		mixed $value,
	): mixed
	{
		if($value === null)
		{
			return null;
		}
		
		if(is_string($value) === false)
		{
			return $value;
		}
		
		if(is_numeric($value)) // a numeric string
		{
			return $value;
		}
		
		return Strings::escapeForHtml($value);
	}
	
	public function render(
		?string $viewScriptFile = null,
		array $variables = [],
	): string
	{
		if($viewScriptFile === null)
		{
			$viewScriptFile = $this->viewScriptFile;
		}
		
		if($viewScriptFile === null)
		{
			throw new Exception('View script cannot be null.');
		}
		
		// assign additional variables
		$this->setMultiple($variables);
		
		ob_start();
		
		try
		{
			include $viewScriptFile;
			
			return ob_get_clean();
		}
		catch(Throwable $throwable)
		{
			$this->eventsService->add($throwable);
			
			// do not render buggy HTML
			// and prevent previous output buffers to be outputted
			ob_end_clean();
			return '';
		}
	}
	
	public function partial(
		?string $viewScriptFile = null,
		array $variables = [],
	): string
	{
		$view = new static;
		
		return $view->render($viewScriptFile, $variables);
	}
	
	public function setMultiple(
		array $variables,
	): void
	{
		foreach($variables as $name => $value)
		{
			$this->__set($name, $value);
		}
	}
	
	public function getVars(): array
	{
		return $this->vars;
	}
	
	public function __toString(): string
	{
		return $this->render();
	}
}
