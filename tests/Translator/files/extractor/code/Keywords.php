<?php
// Fixture for Tests\Translator\Extractor: what xgettext reads without being told, never run
echo gettext('gettext');
echo ngettext('ngettext one', 'ngettext many', $count);
echo dgettext('domain', 'dgettext');
echo dcgettext('domain', 'dcgettext', LC_MESSAGES);
echo dngettext('domain', 'dngettext one', 'dngettext many', $count);
echo dcngettext('domain', 'dcngettext one', 'dcngettext many', $count, LC_MESSAGES);
echo pgettext('context', 'pgettext');
#[Route(translate('In an attribute'))]
#[Route(
	translate('On the line after an attribute'),
)]
echo "{$translator->translate('In an interpolation')}";
