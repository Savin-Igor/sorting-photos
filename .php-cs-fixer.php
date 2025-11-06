<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->exclude('vendor')
    ->exclude('var')
    ->exclude('bin')
    ->exclude('config')
    ->exclude('tests')
    ->name('*.php');

$config = new PhpCsFixer\Config();
return $config->setRules(
    [
        '@Symfony' => true,
        '@PSR12' => true,
        'full_opening_tag' => false,
        'strict_param' => true,
        'strict_comparison' => true,
        'declare_strict_types' => true,
        'short_scalar_cast' => true,
        'no_extra_blank_lines' => false,
        'no_superfluous_phpdoc_tags' => false,
        'phpdoc_annotation_without_dot' => false,
        'general_phpdoc_tag_rename' => false,
        'phpdoc_no_empty_return' => false,
        'phpdoc_to_comment' => false,
        'array_syntax' => ['syntax' => 'short'],
    ]
)->setFinder($finder)->setCacheFile(__DIR__.'/var/.php-cs-fixer.cache');
