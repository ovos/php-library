<?php
declare(strict_types=1);

namespace Ovos\Container;

use Attribute;

/**
 * Inject
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Inject
{
	protected ?string $key = null;
	
	public function __construct(
		?string $key = null,
	)
	{
		$this->key = $key;
	}
	
	public function getKey(): ?string
	{
		return $this->key;
	}
}
