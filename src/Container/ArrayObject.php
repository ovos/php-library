<?php
declare(strict_types=1);

namespace Ovos\Container;

use Attribute;
use Ovos\ArrayObject as BaseArrayObject;
use Override;

/**
 * ArrayObject
 *
 * @author Marcin Gil <mg@ovos.at>
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class ArrayObject implements Injected
{
	protected array $path;
	
	public function __construct(
		...$path,
	)
	{
		$this->path = $path;
	}
	
	public function getPath(): array
	{
		return $this->path;
	}
	
	#[Override]
	public function process(
		object $object,
	): mixed
	{
		/** @var $object BaseArrayObject */
		return $object->getPath($this->path);
	}
}
