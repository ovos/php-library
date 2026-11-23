<?php
// Fixture for Tests\Translator\Extractor: escapes in literals, never run
$translator->translate('It\'s one \\ backslash, \n stays');
$translator->translate("Tab\there, \"quoted\", \x41\101, \$5, \v \u{263A} stay");
$translator->translate(<<<'TEXT'
	Nowdoc \t stays
	TEXT);
