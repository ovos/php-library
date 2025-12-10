<?php
declare(strict_types=1);

namespace Ovos\Model\Mysql\Template;

use Ovos\Model\Mysql;
use Ovos\Model\Mysql\Template;
use Ovos\Pdo\Expression;
use Override;

/**
 * Geolocation
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Geolocation extends Template
{
	protected string $target;
	
	protected string $latitude;
	
	protected string $longitude;
	
	public function __construct(
		string $target,
		string $latitude = 'latitude',
		string $longitude = 'longitude',
	)
	{
		parent::__construct();
		
		$this->target = $target;
		$this->latitude = $latitude;
		$this->longitude = $longitude;
	}	
	
	#[Override]
	public function setUp(
		Mysql $model,
	): void
	{
		$model->setJsonSerializeFilter([
			$this->target, // binary POINT, breaks json_encode
		]);
	}
	
	#[Override]
	public function preSave(
		Mysql $model,
	): void
	{
		// sometimes it's always true, because MySQL keeps it in a different format (precision)
		if($model->isModified($this->latitude, $this->longitude) === false)
		{
			return;
		}
		
		$expression
			= "ST_GeomFromText(CONCAT('POINT(', $this->latitude, ' ', $this->longitude, ')'), 4326)";
		// set value via modifyProperty to record it as modified
		// it may have been prevented from being loaded due to its binary value
		$model->modifyProperty($this->target, new Expression($expression));
	}
}
