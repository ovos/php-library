<?php
declare(strict_types=1);

namespace Ovos\Container;

/**
 * Injected
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
interface Injected
{
	/**
	 * @param object $object
	 *
	 * @return mixed
	 */
	public function process(object $object): mixed;
}
