<?php

/*
 * Coding style: PER Coding Style 3.0 (https://www.php-fig.org/per/coding-style/),
 * WHITESPACE RULES ONLY. Applied with scripts/format.sh and PHP-CS-Fixer
 * 3.95.27 (tests/style/composer.lock); CI runs scripts/format.sh --check
 * (dry run with diff). The cache is passed by format.sh (build/php-cs-fixer/).
 * PHP-CS-Fixer 3.95.27 has no PER-CS 3.1 set yet; 3.0 (@PER-CS3x0) is the
 * newest it ships.
 *
 * Every rule below is one of the rules of @PER-CS3x0, with the configuration
 * that set gives it. Rules of the set that change tokens are left out on
 * purpose and come with a later "modern syntax" change: array_syntax,
 * constant_case, control_structure_braces, elseif, encoding,
 * full_opening_tag, lowercase_cast, lowercase_keywords,
 * lowercase_static_reference, modifier_keywords, new_with_parentheses,
 * no_break_comment, no_closing_tag, no_leading_import_slash,
 * nullable_type_declaration, operator_linebreak, ordered_class_elements,
 * ordered_imports, ordered_types, short_scalar_cast,
 * single_class_element_per_statement, single_import_per_statement,
 * single_trait_insert_per_statement, switch_case_semicolon_to_colon,
 * trailing_comma_in_multiline. operator_linebreak only moves operators, but
 * where a comment sits between an operator and its operand (spendings.php)
 * it changes the token order, so it waits for that change too.
 *
 * The rules only touch whitespace between PHP tokens, never inline HTML,
 * string literals or heredoc/nowdoc content (page output);
 * tests/coverage/token-equivalence.php proves that for a whole diff.
 */

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->name('*.php')
    // Deployment configuration, recorded or generated inputs, and the
    // deliberately broken static-analysis canaries stay byte-exact.
    ->notPath('#^constants\.php$#')
    ->exclude([
        'build',
        'files',
        'proxy',
        'vendor',
        'tests/fixtures',
        'tests/mutation/results',
        'tests/reference/fixtures',
        'tests/reference/php5',
        'tests/static-analysis/canaries',
        'tests/style/vendor',
    ]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setIndent('    ')
    ->setLineEnding("\n")
    ->setFinder($finder)
    ->setRules([
        // Indentation.
        'indentation_type' => true,
        'statement_indentation' => true,
        'array_indentation' => true,
        // Braces and control-structure layout.
        'braces_position' => [
            'allow_single_line_anonymous_functions' => false,
            'allow_single_line_empty_anonymous_classes' => true,
        ],
        'control_structure_continuation_position' => true,
        'single_line_empty_body' => true,
        'class_definition' => [
            'inline_constructor_arguments' => false,
            'space_before_parenthesis' => true,
        ],
        'function_declaration' => ['closure_fn_spacing' => 'none'],
        'method_argument_space' => ['after_heredoc' => false],
        'return_type_declaration' => true,
        'no_multiple_statements_per_line' => true,
        // Spacing.
        'binary_operator_spaces' => ['default' => 'at_least_single_space'],
        'unary_operator_spaces' => ['only_dec_inc' => true],
        'ternary_operator_spaces' => true,
        'concat_space' => ['spacing' => 'one'],
        'cast_spaces' => true,
        'compact_nullable_type_declaration' => true,
        'types_spaces' => true,
        'declare_equal_normalize' => true,
        'no_space_around_double_colon' => true,
        'no_spaces_after_function_name' => true,
        'spaces_inside_parentheses' => true,
        'switch_case_space' => true,
        'attribute_block_no_spaces' => true,
        'single_space_around_construct' => [
            'constructs_followed_by_a_single_space' => [
                'abstract', 'as', 'case', 'catch', 'class', 'const', 'const_import', 'do', 'else',
                'elseif', 'enum', 'final', 'finally', 'for', 'foreach', 'function', 'function_import',
                'if', 'insteadof', 'interface', 'match', 'named_argument', 'namespace', 'new',
                'private', 'protected', 'public', 'readonly', 'static', 'switch', 'trait', 'try',
                'type_colon', 'use', 'use_lambda', 'while',
            ],
            'constructs_preceded_by_a_single_space' => ['as', 'else', 'elseif', 'use_lambda'],
        ],
        // Blank lines.
        'blank_line_after_opening_tag' => true,
        'blank_line_after_namespace' => true,
        'blank_lines_before_namespace' => true,
        'blank_line_between_import_groups' => true,
        'single_line_after_imports' => true,
        'no_blank_lines_after_class_opening' => true,
        'no_extra_blank_lines' => ['tokens' => ['use']],
        'no_whitespace_in_blank_line' => true,
        'single_blank_line_at_eof' => true,
        // Trailing whitespace and line endings.
        'no_trailing_whitespace' => true,
        'no_trailing_whitespace_in_comment' => true,
        'line_ending' => true,
    ]);
