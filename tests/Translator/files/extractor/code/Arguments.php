<?php
// Fixture for Tests\Translator\Extractor: arguments as xgettext takes them, never run
$translator->translate($query, $route);
$translator->translate("Hello $name");
$translator->translate("Hello {$user->name}");
$translator->translate('Hello ' . $name);
$translator->translate($name . ', welcome');
$translator->translate($count > 1 ? 'Many' : 'One');
$translator->translate($title ?: 'Untitled');
$translator->translate(($title ?: 'In parentheses'));
$translator->translate((string)'Cast');
$translator->translate(phrase: 'Named');
$translator->translate(['In brackets']);
$translator->translatePlural('{n} cat', $plural, $count);
$translator->translateContext($context, 'Close');
// $translator->translate('In a comment');
$translator->untranslated('Not a keyword');
$translator->translate('Kept');
