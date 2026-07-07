<?php
declare(strict_types=1);

namespace Tests;

use Ovos\Strings as Subject;
use Ovos\Test;

use function strlen;

/**
 * Strings - the case/slug/escape helpers behind routing, naming and output
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Strings extends Test
{
	public function studlyCaseJoinsOnDashesAndUnderscores(): bool
	{
		return Subject::studlyCase('admin-banner') === 'AdminBanner'
			&& Subject::studlyCase('user_profile') === 'UserProfile'
			&& Subject::studlyCase('mixed-case_word') === 'MixedCaseWord'
			&& Subject::studlyCase('already') === 'Already';
	}
	
	public function camelCaseLowercasesTheFirstWord(): bool
	{
		return Subject::camelCase('company-id') === 'companyId'
			&& Subject::camelCase('user_profile') === 'userProfile'
			&& Subject::camelCase('single') === 'single';
	}
	
	public function snakeCaseSplitsCamelHumps(): bool
	{
		return Subject::snakeCase('companyId') === 'company-id'
			&& Subject::snakeCase('UserProfile') === 'user-profile'
			&& Subject::snakeCase('company_id', '_') === 'company_id' // already lower
			&& Subject::snakeCase('lower') === 'lower';
	}
	
	public function escapeForHtmlNeutralisesMarkupAndQuotes(): bool
	{
		$escaped = Subject::escapeForHtml('<a href="x">Tom & "Jerry"\'s</a>');
		
		return str_contains($escaped, '<') === false
			&& str_contains($escaped, '&lt;') === true
			&& str_contains($escaped, '&amp;') === true
			&& str_contains($escaped, '&quot;') === true
			&& str_contains($escaped, '&#039;') === true;
	}
	
	public function shortenRespectsMultibyteAndOnlyTruncatesWhenNeeded(): bool
	{
		return Subject::shorten('short', 20) === 'short' // under the limit, untouched
			&& Subject::shorten('abcdefghij', 5) === 'ab...' // 5 incl. the ending
			// multibyte must not be cut mid-character or miscounted
			&& Subject::shorten('zażółć', 100) === 'zażółć';
	}
	
	public function wrapSurroundsWithTheDelimiter(): bool
	{
		return Subject::wrap('x', '%') === '%x%'
			&& Subject::wrap('', '"') === '""';
	}
	
	public function entitiesEncodesEveryByte(): bool
	{
		return Subject::entities('AB') === '&#65;&#66;';
	}
	
	public function randomHonoursLengthAndAlphabet(): bool
	{
		$value = Subject::random(40, 'ab');
		
		if(strlen($value) !== 40)
		{
			return false;
		}
		
		// only characters from the given alphabet
		return preg_match('~^[ab]+$~', $value) === 1
			&& strlen(Subject::random(0)) === 0;
	}
	
	public function randomColorIsSixHexDigits(): bool
	{
		return preg_match('~^[0-9a-f]{6}$~', Subject::randomColor()) === 1;
	}
	
	public function slugifyProducesUrlSafeText(): bool
	{
		return Subject::slugify('Leistung ist das Fundament!') === 'leistung-ist-das-fundament'
			// German transliteration: ü -> ue, ä -> ae
			&& Subject::slugify('Über Ähnliches') === 'ueber-aehnliches';
	}
}
