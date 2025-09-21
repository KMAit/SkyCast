<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->exclude(['var', 'vendor']);

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,

        // Align "=>" nicely (match/case arrays etc.)
        'binary_operator_spaces' => [
            'default'   => 'align_single_space_minimal',
            'operators' => [
                '=>' => 'align_single_space_minimal',
            ],
        ],

        // Do NOT strip alignment around "=>"
        'no_multiline_whitespace_around_double_arrow' => false,

        // No Yoda conditions
        'yoda_style' => false,

        'array_syntax'      => ['syntax' => 'short'],
        'no_unused_imports' => true,
    ])
    ->setRiskyAllowed(true)
    ->setFinder($finder);
