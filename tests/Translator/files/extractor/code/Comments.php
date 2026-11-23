<?php
// Fixture for Tests\Translator\Extractor: comments for the translator, never run
// translators: a greeting on the first page
$translator->translate('Welcome');
// TRANSLATORS: Poedit's last --add-comments wins, which is the lower-case tag
$translator->translate('Upper case tag');
// translators: above a line of other code
$other = 1;
$translator->translate('Below other code');
// translators: only the first call of the line takes it
$translator->translate('First') . $translator->translate('Second');
/*
 * translators: a block
 * of two lines
 */
$translator->translate('Block');
