<?php
declare(strict_types=1);

namespace Ovos;

use function filter_var;

/**
 * Response
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Request
{
	/**#@+
	 * Methods
	 * 
	 * @var string
	 */
	public const string METHOD_GET = 'GET';
	public const string METHOD_POST = 'POST';
	public const string METHOD_PUT = 'PUT';
	public const string METHOD_DELETE = 'DELETE';
	public const string METHOD_HEAD = 'HEAD';
	/**#@-*/
	
	/**
	 * @var ?Url
	 */
	protected ?Url $_url = null;
	
	/**
	 * The locale
	 *
	 * @var ?Locale
	 */
	protected ?Locale $_locale = null;
	
	/**
	 * The controller
	 * 
	 * @var string
	 */
	protected string $_controller = 'index';
	
	/**
	 * The controller class
	 * 
	 * @var string
	 */
	protected string $_controllerClass = 'Index';
	
	/**
	 * @var ?Controller
	 */
	protected ?Controller $_controllerInstance = null;
	
	/**
	 * The action
	 * 
	 * @var string
	 */
	protected string $_action = 'index';
	
	/**
	 * The action method
	 * 
	 * @var string
	 */
	protected string $_actionMethod = 'index';
	
	/**
	 * Params
	 *
	 * @var array
	 */
	protected array $_params = [];
	
	/**
	 * Construct
	 */
	public function __construct()
	{
	}
	
	/**
	 * @return Url
	 */
	public function getUrl(): Url
	{
		if($this->_url === null)
		{
			$this->_url = new Url;
		}
		
		return $this->_url;
	}
	
	/**
	 * @param Locale $locale
	 *
	 * @return self
	 */
	public function setLocale(Locale $locale): self
	{
		$this->_locale = $locale;
		
		return $this;
	}
	
	/**
	 * @return Locale
	 */
	public function getLocale(): Locale
	{
		if($this->_locale === null)
		{
			$this->_locale = $this->getUrl()->getLocale();
		}
		
		return $this->_locale;
	}
	
	/**
	 * @param string $controller
	 *
	 * @return self
	 */
	public function setController(string $controller): self
	{
		$this->_controller = $controller;
		
		return $this;
	}
	
	/**
	 * @return string
	 */
	public function getController(): string
	{
		return $this->_controller;
	}
	
	/**
	 * @param string $class
	 *
	 * @return self
	 */
	public function setControllerClass(string $class): self
	{
		$this->_controllerClass = $class;
		
		return $this;
	}
	
	/**
	 * @return string
	 */
	public function getControllerClass(): string
	{
		return $this->_controllerClass;
	}
	
	/**
	 * @param Controller $instance
	 *
	 * @return self
	 */
	public function setControllerInstance(Controller $instance): self
	{
		$this->_controllerInstance = $instance;
		
		return $this;
	}
	
	/**
	 * @return Controller
	 */
	public function getControllerInstance(): Controller
	{
		return $this->_controllerInstance;
	}
	
	/**
	 * @param string $action
	 *
	 * @return self
	 */
	public function setAction(string $action): self
	{
		$this->_action = $action;
		
		return $this;
	}
	
	/**
	 * @return string
	 */
	public function getAction(): string
	{
		return $this->_action;
	}
	
	/**
	 * @param string $actionMethod
	 *
	 * @return self
	 */
	public function setActionMethod(string $actionMethod): self
	{
		$this->_actionMethod = $actionMethod;
		
		return $this;
	}
	
	/**
	 * @return string
	 */
	public function getActionMethod(): string
	{
		return $this->_actionMethod;
	}
	
	/**
	 * @param mixed $param
	 *
	 * @return self
	 */
	public function addParam(mixed $param): self
	{
		$this->_params[] = $param;
		
		return $this;
	}
	
	/**
	 * @param array $params
	 *
	 * @return self
	 */
	public function setParams(array $params = []): self
	{
		$this->_params = $params;
		
		return $this;
	}
	
	/**
	 * @return array
	 */
	public function getParams(): array
	{
		return $this->_params;
	}
	
	/**
	 * @param ?string $name
	 * @param ?string $default
	 * @see http://php.net/filter_var
	 * @param ?int $filter
	 * @param int $options
	 *
	 * @return null|array|string
	 */
	public function get(?string $name = null,
		?string $default = null,
		?int $filter = null,
		int $options = FILTER_NULL_ON_FAILURE) : null|array|string // filter_var arguments
	{
		if($name === null)
		{
			return $_GET;
		}
		
		if(!isset($_GET[$name]))
		{
			if($filter === null)
			{
				return $default;
			}
			
			return filter_var($filter, $options);
		}
		
		return $_GET[$name];
	}
	
	/**
	 * @param ?string $name
	 * @param null|string|array|int $default
	 * @see http://php.net/filter_var
	 * @param ?int $filter
	 * @param int $options
	 *
	 * @return null|array|string
	 */
	public function getPost(?string $name = null,
		null|string|array|int $default = null,
		?int $filter = null,
		int $options = FILTER_NULL_ON_FAILURE): null|array|string // filter_var arguments
	{
		if($name === null)
		{
			return $_POST;
		}
		
		if(!isset($_POST[$name]))
		{
			if($filter === null)
			{
				return $default;
			}
			
			return filter_var($filter, $options);
		}
		
		return $_POST[$name];
	}
	
	/**
	 * @param string $name
	 *
	 * @return ?string
	 */
	public function getServer(string $name): ?string
	{
		if(!isset($_SERVER[$name]))
		{
			return null;
		}
		
		return $_SERVER[$name];
	}
	
	/**
	 * @param string $method
	 *
	 * @return bool
	 */
	public function isMethod(string $method): bool
	{
		return $this->getServer('REQUEST_METHOD') === $method;
	}
	
	/**
	 * @return bool
	 */
	public function isGet(): bool
	{
		return $this->isMethod(self::METHOD_GET);
	}
	
	/**
	 * @return bool
	 */
	public function isPost(): bool
	{
		return $this->isMethod(self::METHOD_POST);
	}
	
	/**
	 * @return bool
	 */
	public function isPut(): bool
	{
		return $this->isMethod(self::METHOD_PUT);
	}
	
	/**
	 * @return bool
	 */
	public function isDelete(): bool
	{
		return $this->isMethod(self::METHOD_DELETE);
	}
	
	/**
	 * @return bool
	 */
	public function isHead(): bool
	{
		return $this->isMethod(self::METHOD_HEAD);
	}
	
	/**
	 * Is the request a Javascript XMLHttpRequest?
	 *
	 * Supports emulated method with X_REQUESTED_WITH POST param
	 *
	 * @return bool
	 */
	public function isXmlHttpRequest(): bool
	{
		return ($this->getPost('X_REQUESTED_WITH') === 'XMLHttpRequest'
			|| $this->getServer('HTTP_X_REQUESTED_WITH') === 'XMLHttpRequest');
	}
	
	/**
	 * Is the application run from CLI (command line interface)
	 *
	 * @return bool
	 */
	public function isCli(): bool
	{
		return PHP_SAPI === 'cli';
	}
	
	/**
	 * Is the application run from HTTP
	 *
	 * @return bool
	 */
	public function isHttp(): bool
	{
		return $this->isCli() === false;
	}
	
	/**
	 * Is the request secure
	 *
	 * @return bool
	 */
	public function isSecure(): bool
	{
		// apache
		if($this->getServer('HTTPS') === 'on')
		{
			return true;
		}
		
		// proxy
		if($this->getServer('HTTP_X_FORWARDED_PROTO') === 'https')
		{
			return true;
		}
		
		return false;
	}
	
	/**
	 * Is this a HTTP debug mode
	 *
	 * @return bool
	 */
	public function isHttpDebug(): bool
	{
		return isset($_GET['debug']);
	}
}
