<?php
declare(strict_types=1);

namespace Tests\Controller;

use Ovos\Application;
use Ovos\ArrayObject;
use Ovos\Controller;
use Ovos\Test;
use ReflectionClass;
use ReflectionProperty;

use function in_array;
use function Ovos\container;

/**
 * GroupMatching - which controllers a configured group entry covers
 *
 * A group in environments.yml lists controllers and the plugins it adds or
 * skips for them; the public group of an application skips Auth. An entry
 * covers the controller of that name and every controller in the namespace
 * below it - and nothing else. The prefix match it replaced covered every
 * sibling that merely began with the same letters, so codesafe's public
 * `Api\V1\Shield` (a site's key-gated rule pull) also published
 * `Api\V1\ShieldRules`, the admin-only rule editor, to anyone.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class GroupMatching extends Test
{
	public function anEntryCoversTheControllerOfItsName(): bool
	{
		return Controller::matchesController('Api\V1\Shield', 'Api\V1\Shield')
			&& Controller::matchesController('Index', 'Index');
	}
	
	public function anEntryCoversTheNamespaceBelowIt(): bool
	{
		return Controller::matchesController('System\Events', 'System')
			&& Controller::matchesController('Admin\Users\Roles', 'Admin')
			&& Controller::matchesController('Admin\Users\Roles', 'Admin\Users');
	}
	
	/**
	 * The defect itself: a sibling sharing the first letters is a different
	 * controller, whatever the entry's group does to it
	 */
	public function aSiblingThatSharesTheLettersIsNotCovered(): bool
	{
		return Controller::matchesController('Api\V1\ShieldRules', 'Api\V1\Shield') === false
			&& Controller::matchesController('Users', 'User') === false
			&& Controller::matchesController('Indexer', 'Index') === false
			&& Controller::matchesController('SystemTools\Cache', 'System') === false
			// nor does an entry cover the namespace ABOVE it
			&& Controller::matchesController('System', 'System\Events') === false;
	}
	
	/**
	 * An empty entry - a stray "-" in the yml - used to cover every
	 * controller of the application (every name starts with ""); it covers
	 * none now
	 */
	public function anEmptyEntryCoversNothing(): bool
	{
		return Controller::matchesController('Api\V1\Users', '') === false;
	}
	
	/**
	 * The group resolution uses the rule: a group skipping Auth for
	 * `Api\V1\Shield` leaves Auth on `Api\V1\ShieldRules`, and still takes it
	 * off the entry's own controller and a controller below it
	 */
	public function aGroupSkipsItsPluginsOnlyForTheControllersItCovers(): bool
	{
		$interface = container()
			->getClass(Application::class)
			->getInterface();
		$group = new ArrayObject([
			'controllers' => ['Api\V1\Shield', 'System'],
			$interface => ['skip' => ['Auth']],
		]);
		
		return $this->resolved($group, 'Api\V1\ShieldRules') === ['Auth', 'Demo']
			&& $this->resolved($group, 'Systems') === ['Auth', 'Demo']
			&& $this->resolved($group, 'Api\V1\Shield') === ['Demo']
			&& $this->resolved($group, 'System\Sessions') === ['Demo'];
	}
	
	/**
	 * The plugins a group leaves for a controller, from Auth + Demo. The
	 * controller is made without its constructor, which would register the
	 * running application's own plugins; getGroupPlugins() reads only the
	 * application's interface.
	 */
	protected function resolved(
		ArrayObject $group,
		string $currentController,
	): array
	{
		$controller = (new ReflectionClass(Controller::class))
			->newInstanceWithoutConstructor();
		(new ReflectionProperty(Controller::class, 'app'))
			->setValue($controller, container()->getClass(Application::class));
		
		$plugins = $controller->getGroupPlugins(
			new ArrayObject(['Auth', 'Demo']),
			$group,
			$currentController,
		);
		
		$names = [];
		foreach($plugins as $plugin)
		{
			if(in_array($plugin, ['Auth', 'Demo'], true))
			{
				$names[] = $plugin;
			}
		}
		
		return $names;
	}
}
