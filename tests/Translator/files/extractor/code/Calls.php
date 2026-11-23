<?php
// Fixture for Tests\Translator\Extractor: one call of each keyword, never run
$translator->translate('Plain');
$translator->translatePlural('{n} file', '{n} files', $count);
$translator->translateContext('verb', 'Open');
$translator->translatePluralContext('inbox', '{n} message', '{n} messages', $count);
echo _('Underscore');
echo _n('{n} day', '{n} days', $count);
$translator->translate(
	'Spread over '
	. 'two lines',
);
$translator->translate('Plain');
