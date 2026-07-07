<?php
declare(strict_types=1);

namespace Tests\Controller;

use Ovos\Controller;
use Ovos\Exception\RuntimeException;
use Ovos\Test;

/**
 * PluginResolution - how configured plugin names become classes
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class PluginResolution extends Test
{
	public function absoluteNameIsUsedVerbatim(): bool
	{
		return Controller::resolvePluginClass('\Ovos\Plugins\Security\Csrf')
			=== '\Ovos\Plugins\Security\Csrf';
	}
	
	public function bareNameFallsBackToTheFrameworkNamespace(): bool
	{
		// no Plugins\Security\Csrf exists in this application - the
		// cascade lands on the framework class, so configs can say
		// "Security\Csrf" instead of "\Ovos\Plugins\Security\Csrf"
		return Controller::resolvePluginClass('Security\Csrf')
			=== 'Ovos\Plugins\Security\Csrf'
			&& Controller::resolvePluginClass('Cache\Page')
				=== 'Ovos\Plugins\Cache\Page';
	}
	
	public function unknownNamesThrowNamingBothCandidates(): bool
	{
		try
		{
			Controller::resolvePluginClass('No\Such\Plugin');
		}
		catch(RuntimeException $exception)
		{
			return str_contains($exception->getMessage(), 'Plugins\No\Such\Plugin')
				&& str_contains($exception->getMessage(), 'Ovos\Plugins\No\Such\Plugin');
		}
		
		return false;
	}
	
	public function unknownAbsoluteNameThrows(): bool
	{
		try
		{
			Controller::resolvePluginClass('\No\Such\Plugin');
		}
		catch(RuntimeException)
		{
			return true;
		}
		
		return false;
	}
}
