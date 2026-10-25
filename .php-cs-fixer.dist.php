<?php

/*
 * Coding style: PER Coding Style 3.0 (https://www.php-fig.org/per/coding-style/),
 * the full @PER-CS3x0 set of PHP-CS-Fixer 3.95.27 (tests/style/composer.lock).
 * Applied with scripts/format.sh; CI runs scripts/format.sh --check (dry run
 * with diff). The cache lives in build/php-cs-fixer/. PHP-CS-Fixer 3.95.27
 * has no PER-CS 3.1 set yet; 3.0 is the newest it ships.
 *
 * History: the whitespace rules came first (proven by
 * tests/coverage/token-equivalence.php); the token-changing rules of the set
 * (braces, elseif, no closing tag, trailing commas, short arrays in tests,
 * modifier keywords, ...) followed in one change, reviewed with
 * tests/coverage/ast-equivalence.sh and the reference comparison.
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
        'tests/reference/php85',
        'tests/static-analysis/canaries',
        'tests/style/vendor',
    ]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setIndent('    ')
    ->setLineEnding("\n")
    // scripts/format.sh passes its own cache file (or none); direct runs
    // cache here.
    ->setCacheFile(__DIR__ . '/build/php-cs-fixer/php-cs-fixer.cache')
    ->setFinder($finder)
    ->setRules(['@PER-CS3x0' => true]);
