<?php

/*
 * Copyright (C) Damien Dart, <damiendart@pobox.com>.
 * This file is distributed under the MIT licence. For more information,
 * please refer to the accompanying "LICENCE" file.
 */

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$header = <<<'HEADER'
Copyright (C) Damien Dart, <damiendart@pobox.com>.
This file is distributed under the MIT licence. For more information,
please refer to the accompanying "LICENCE" file.
HEADER;

return (new Config())
    ->setRules(
        [
            '@PhpCsFixer' => true,
            '@PSR12' => true,
            'array_syntax' => ['syntax' => 'short'],
            'concat_space' => ['spacing' => 'one'],
            'declare_strict_types' => true,
            'header_comment' => [
                'header' => $header,
                'location' => 'after_open',
                'separate' => 'both',
            ],
            'native_function_invocation' => true,
            'no_unused_imports' => true,
            'multiline_whitespace_before_semicolons' => [
                'strategy' => 'no_multi_line',
            ],
            'ordered_imports' => ['sort_algorithm' => 'alpha'],
            'phpdoc_align' => ['align' => 'left'],
            'php_unit_method_casing' => ['case' => 'snake_case'],
            'php_unit_test_class_requires_covers' => true,
            'static_lambda' => true,
            'trailing_comma_in_multiline' => [
                'elements' => ['arrays', 'arguments', 'parameters'],
            ],
            'void_return' => true,
        ],
    )
    ->setFinder(
        Finder::create()
            ->ignoreDotFiles(false)
            ->ignoreVCSIgnored(true)
            ->in(__DIR__),
    );
