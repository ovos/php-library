<?php
declare(strict_types=1);

namespace Ovos\Container;

use Attribute;
use Ovos\ArrayObject as BaseArrayObject;
use Override;

/**
 * The first of several config sections that is there — a section that was
 * renamed, read under its new name and under the old one until every
 * deployment has moved: #[Inject('config')] #[FirstOf('codesafe', 'console')]
 *
 * @author Marcin Gil <mg@ovos.at>
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class FirstOf implements Injected
{
	protected array $sections;
	
	public function __construct(
		string ...$sections,
	)
	{
		$this->sections = $sections;
	}
	
	#[Override]
	public function process(
		object $object,
	): mixed
	{
		/** @var $object BaseArrayObject */
		foreach($this->sections as $section)
		{
			$value = $object->getPath([$section]);
			if($value !== null)
			{
				return $value;
			}
		}
		
		return null;
	}
}
