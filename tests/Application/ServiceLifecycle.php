<?php
declare(strict_types=1);

namespace Tests\Application;

use Ovos\Application;
use Ovos\Container;
use Ovos\Request;
use Ovos\Service;
use Ovos\Services;
use Ovos\Service\Codesafe\Sender;
use Ovos\Service\Events;
use Ovos\Test;
use ReflectionClass;
use RuntimeException;

class ServiceLifecycle extends Test
{
	public function callbacksFinishBeforeConsumersAndEventsClearLast(): bool
	{
		$events = new LifecycleEvents;
		$first = new LifecycleConsumer($events, true);
		$last = new LifecycleConsumer($events);
		$app = new LifecycleApplication($events, [$first, $last]);
		$app->afterResponse(static fn() => $events->add('deferred'));
		$app->finish();
		$app->finish();
		return $first->seen === [['deferred']]
			&& $last->seen === [['deferred']]
			&& $events->toArray() === [];
	}

	public function reusedSenderRegistersOnceForEachApplication(): bool
	{
		$events = new LifecycleEvents;
		$sender = new LifecycleSender(null);
		for($request = 0; $request < 2; $request++)
		{
			$app = new LifecycleApplication($events, [$sender]);
			$sender->boot($app);
			$events->log(new RuntimeException('logged'));
			$events->add(new RuntimeException('collected'));
			$app->finish();
		}
		return $sender->captured === 2
			&& $sender->flushed === 2
			&& $sender->collected === [1, 1]
			&& $events->count() === 0;
	}
}

class LifecycleEvents extends Events
{
	public function __construct() {}
}

class LifecycleApplication extends Application
{
	public function __construct(Events $events, array $services)
	{
		$this->container = new Container;
		$this->container->registerValue(Events::SYMBOL, $events);
		$this->container->registerValue(Services::class, new Services($this->container));
		$this->request = (new ReflectionClass(Request::class))->newInstanceWithoutConstructor();
		$this->activeServices = $services;
	}

	public function finish(): void
	{
		$this->terminateServices();
	}
}

class LifecycleConsumer extends Service
{
	public array $seen = [];

	public function __construct(protected Events $events, protected bool $fail = false) {}

	public function terminate(): void
	{
		$this->seen[] = $this->events->toArray();
		if($this->fail)
		{
			throw new RuntimeException('consumer failed');
		}
	}
}

class LifecycleSender extends Sender
{
	public int $captured = 0;
	public int $flushed = 0;
	public array $collected = [];

	public function captureException(\Throwable $event, array $extra = [], ?int $priority = null): static
	{
		$this->captured++;
		return $this;
	}

	public function flush(): void
	{
		$this->flushed++;
		$this->collected[] = $this->app->getServices()->events->count();
	}
}
