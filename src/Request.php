<?php
declare(strict_types=1);

namespace Ovos;

use function filter_var;
use function strtoupper;

/**
 * Response
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Request
{
	// Methods
	public const string METHOD_GET = 'GET';
	public const string METHOD_POST = 'POST';
	public const string METHOD_PUT = 'PUT';
	public const string METHOD_PATCH = 'PATCH'; // RFC 5789 - partial update
	public const string METHOD_DELETE = 'DELETE';
	public const string METHOD_HEAD = 'HEAD';
	public const string METHOD_OPTIONS = 'OPTIONS'; // CORS preflight, capabilities
	/**
	 * HTTP QUERY (IETF draft) - a safe, idempotent, cacheable request that
	 * carries a body; the correct verb for search endpoints that today
	 * POST a filter payload. Still a draft: fetch() can send it, but caches
	 * and proxies may not treat it as safe yet.
	 */
	public const string METHOD_QUERY = 'QUERY';
	
	protected ?Url $url = null;
	
	/**
	 * The locale
	 */
	protected ?Locale $locale = null;
	
	/**
	 * The controller
	 */
	protected string $controller = 'index';
	
	/**
	 * The controller class
	 */
	protected string $controllerClass = 'Index';
	
	protected ?Controller $controllerInstance = null;
	
	/**
	 * The action
	 */
	protected string $action = 'index';
	
	/**
	 * The action method
	 */
	protected string $actionMethod = 'index';
	
	protected array $params = [];
	
	public function __construct()
	{
	}
	
	public function getUrl(): Url
	{
		if($this->url === null)
		{
			$this->url = new Url;
		}
		
		return $this->url;
	}
	
	public function setLocale(
		Locale $locale,
	): static
	{
		$this->locale = $locale;
		
		return $this;
	}
	
	public function getLocale(): Locale
	{
		if($this->locale === null)
		{
			$this->locale = $this->getUrl()->getLocale();
		}
		
		return $this->locale;
	}
	
	public function setController(
		string $controller,
	): static
	{
		$this->controller = $controller;
		
		return $this;
	}
	
	public function getController(): string
	{
		return $this->controller;
	}
	
	public function setControllerClass(
		string $class,
	): static
	{
		$this->controllerClass = $class;
		
		return $this;
	}
	
	public function getControllerClass(): string
	{
		return $this->controllerClass;
	}
	
	public function setControllerInstance(
		Controller $instance,
	): static
	{
		$this->controllerInstance = $instance;
		
		return $this;
	}
	
	public function getControllerInstance(): Controller
	{
		return $this->controllerInstance;
	}
	
	public function setAction(
		string $action,
	): static
	{
		$this->action = $action;
		
		return $this;
	}
	
	public function getAction(): string
	{
		return $this->action;
	}
	
	public function setActionMethod(
		string $actionMethod,
	): static
	{
		$this->actionMethod = $actionMethod;
		
		return $this;
	}
	
	public function getActionMethod(): string
	{
		return $this->actionMethod;
	}
	
	public function addParam(
		mixed $param,
	): static
	{
		$this->params[] = $param;
		
		return $this;
	}
	
	public function setParams(
		array $params = [],
	): static
	{
		$this->params = $params;
		
		return $this;
	}
	
	public function getParams(): array
	{
		return $this->params;
	}
	
	public function get(
		?string $name = null,
		?string $default = null,
		?int $filter = null,
		int $options = FILTER_NULL_ON_FAILURE, // @see http://php.net/filter_var
	): null|array|string
	{
		if($name === null)
		{
			return $_GET;
		}
		
		if(isset($_GET[$name]) === false)
		{
			return $default;
		}
		
		if($filter === null)
		{
			return $_GET[$name];
		}
		
		return filter_var($_GET[$name], $filter, $options);
	}
	
	public function getPost(
		?string $name = null,
		null|string|array|int $default = null,
		?int $filter = null,
		int $options = FILTER_NULL_ON_FAILURE, // http://php.net/filter_var
	): null|array|string
	{
		if($name === null)
		{
			return $_POST;
		}
		
		if(isset($_POST[$name]) === false)
		{
			return $default;
		}
		
		if($filter === null)
		{
			return $_POST[$name];
		}
		
		return filter_var($_POST[$name], $filter, $options);
	}
	
	public function getServer(
		string $name,
	): ?string
	{
		if(isset($_SERVER[$name]) === false)
		{
			return null;
		}
		
		return $_SERVER[$name];
	}
	
	/**
	 * The request method, uppercased; empty string when there is none
	 * (CLI). Prefer this over getServer('REQUEST_METHOD').
	 */
	public function getMethod(): string
	{
		return strtoupper((string)$this->getServer('REQUEST_METHOD'));
	}
	
	public function isMethod(
		string $method,
	): bool
	{
		return $this->getMethod() === strtoupper($method);
	}
	
	public function isGet(): bool
	{
		return $this->isMethod(self::METHOD_GET);
	}
	
	public function isPost(): bool
	{
		return $this->isMethod(self::METHOD_POST);
	}
	
	public function isPut(): bool
	{
		return $this->isMethod(self::METHOD_PUT);
	}
	
	public function isPatch(): bool
	{
		return $this->isMethod(self::METHOD_PATCH);
	}
	
	public function isDelete(): bool
	{
		return $this->isMethod(self::METHOD_DELETE);
	}
	
	public function isHead(): bool
	{
		return $this->isMethod(self::METHOD_HEAD);
	}
	
	public function isOptions(): bool
	{
		return $this->isMethod(self::METHOD_OPTIONS);
	}
	
	/**
	 * The HTTP QUERY method (IETF draft) - a body-carrying search request
	 */
	public function isQuery(): bool
	{
		return $this->isMethod(self::METHOD_QUERY);
	}
	
	/**
	 * Is the request a JavaScript XMLHttpRequest?
	 *
	 * Supports emulated method with X_REQUESTED_WITH POST param
	 */
	public function isXmlHttpRequest(): bool
	{
		return ($this->getPost('X_REQUESTED_WITH') === 'XMLHttpRequest'
			|| $this->getServer('HTTP_X_REQUESTED_WITH') === 'XMLHttpRequest');
	}
	
	/**
	 * Is the application run from CLI (command line interface)?
	 */
	public function isCli(): bool
	{
		return PHP_SAPI === 'cli';
	}
	
	/**
	 * Is the application run from HTTP?
	 */
	public function isHttp(): bool
	{
		return $this->isCli() === false;
	}
	
	/**
	 * Is the request secure?
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
	 * Is this an HTTP debug mode?
	 */
	public function isHttpDebug(): bool
	{
		return isset($_GET['debug']);
	}
}
