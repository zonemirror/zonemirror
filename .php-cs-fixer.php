<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/plugin')
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setUsingCache(true)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'binary_operator_spaces' => ['default' => 'align_single_space_minimal'],
        'blank_line_before_statement' => true,
        'concat_space' => ['spacing' => 'one'],
        'fully_qualified_strict_types' => true,
        'native_function_invocation' => [
            'include' => ['@all'],
            'scope' => 'namespaced',
        ],
        'no_unused_imports' => true,
        'ordered_imports' => true,
        'single_quote' => true,
        'trailing_comma_in_multiline' => true,
    ])
    ->setFinder($finder);














