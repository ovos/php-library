<?php
declare(strict_types=1);

namespace Ovos\Form\Validator;

use Ovos\Form\Validator;

/**
 * Gate — a validator over the RAW value's SHAPE, judged before anything
 * else touches it. Element::asText()/asNumber()/asCollection() install one:
 * Element::isValid() reports its failure as the field's single error, and
 * Element::getValue() consults conforms() so filters and normalizers never
 * run on a shape the gate refuses — they are written assuming the gate
 * protected them.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
abstract class Gate extends Validator
{
	/**
	 * Whether the value has the gate's shape — pure: no errors, no state
	 */
	abstract public function conforms(
		mixed $value,
	): bool;
}
