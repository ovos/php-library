<?php
declare(strict_types=1);

namespace Ovos\Response\Redirect;

use Ovos\Response\Redirect;
use Override;

/**
 * ExternalRedirect
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class ExternalRedirect extends Redirect
{
	protected string $externalUrl;
	
	public function __construct(
		string $externalUrl,
	)
	{
		parent::__construct();
		
		$this->externalUrl = $externalUrl;
	}
	
	#[Override]
	public function __toString(): string
	{
		return $this->externalUrl;
	}
}
