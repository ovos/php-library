<?php
declare(strict_types=1);

namespace Tests;

use Ovos\Environment as Subject;
use Ovos\Test;

/**
 * Environment - the !ENV / !ENV_LIST YAML tag resolution behind config
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Environment extends Test
{
	/**
	 * A nested .env section flattens to the SECTION[KEY] form the tags read
	 */
	protected function subject(): Subject
	{
		return new Subject([
			'ENV' => 'development',
			'UI_AUTH' => [
				'WHITELIST' => '127.0.0.1, 91.196.8.71 ,116.203.34.163',
				'EMPTY' => '',
			],
		]);
	}

	public function envTagResolvesAScalarAndFallsBackToTheDefault(): bool
	{
		$env = $this->subject();

		return $env->getYamlTag('ENV', '!ENV', 0) === 'development'
			&& $env->getYamlTag('UI_AUTH[WHITELIST]', '!ENV', 0) === '127.0.0.1, 91.196.8.71 ,116.203.34.163'
			// missing key with no default -> null; with a "|default" -> the default
			&& $env->getYamlTag('MISSING[KEY]', '!ENV', 0) === null
			&& $env->getYamlTag('MISSING[KEY]|fallback', '!ENV', 0) === 'fallback';
	}

	public function envListTagSplitsTrimsAndDropsEmpties(): bool
	{
		$list = $this->subject()->getYamlListTag('UI_AUTH[WHITELIST]', '!ENV_LIST', 0);

		return $list === ['127.0.0.1', '91.196.8.71', '116.203.34.163'];
	}

	public function envListTagYieldsAnEmptyListForMissingOrEmptyValues(): bool
	{
		$env = $this->subject();

		return $env->getYamlListTag('MISSING[KEY]', '!ENV_LIST', 0) === []
			&& $env->getYamlListTag('UI_AUTH[EMPTY]', '!ENV_LIST', 0) === [];
	}

	public function envListTagSupportsAPipeDefault(): bool
	{
		$list = $this->subject()
			->getYamlListTag('MISSING[KEY]|127.0.0.1,::1', '!ENV_LIST', 0);

		return $list === ['127.0.0.1', '::1'];
	}
}
