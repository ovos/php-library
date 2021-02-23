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
	 * @return null|string|SimpleXMLElement
	 */
	public static function format(null|string|SimpleXMLElement $xml): ?string
	{
		if($xml === null)
		{
			return null;
		}

		if(($xml instanceof SimpleXMLElement) === false)
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
