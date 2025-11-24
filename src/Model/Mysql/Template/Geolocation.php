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
	/**
	 * @var string
	 */
	protected string $_target;
	
	/**
	 * @var string
	 */
	protected string $_latitude;
	
	/**
	 * @var string
	 */
	protected string $_longitude;
	
	/**
	 * @param string $target
	 * @param string $latitude
	 * @param string $longitude
	 */
	public function __construct(string $target,
		string $latitude = 'latitude',
		string $longitude = 'longitude',
	)
	{
		parent::__construct();
		
		$this->_target = $target;
		$this->_latitude = $latitude;
		$this->_longitude = $longitude;
	}	
	
	/**
	 * @param Mysql $model
	 */
	#[Override]
	public function setUp(Mysql $model): void
	{
		$model->setJsonSerializeFilter([
			$this->_target, // binary POINT, breaks json_encode
		]);
	}	
	
	/**
	 * @param Mysql $model
	 */
	#[Override]
	public function preSave(Mysql $model): void
	{
		// sometimes it's always true, because MySQL keeps it in a different format (precision)
		if($model->isModified($this->_latitude, $this->_longitude) === false)
		{
			return;
		}	
		
		$expression = "ST_GeomFromText(CONCAT('POINT(', $this->_latitude, ' ', $this->_longitude, ')'), 4326)";
		// set value via modifyProperty to record it as modified
		// it may have been prevented from being loaded due to its binary value
		$model->modifyProperty($this->_target, new Expression($expression));
	}
}
