<?php
declare(strict_types=1);

namespace Ovos;

use DOMDocument;
use SimpleXMLElement;

/**
 * Xml
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class Xml
{
	/**
	 * @param mixed $xml
	 *
	 * @return string|null
	 */
	public static function format($xml): ?string
	{
		if($xml === null)
		{
			return null;
		}

		if(!($xml instanceof SimpleXMLElement))
		{
			$xml = simplexml_load_string($xml);
		}

		$dom = new DOMDocument;
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput = true;
		$dom->loadXML($xml->asXML());

		return $dom->saveXML($dom->documentElement);
	}
}
