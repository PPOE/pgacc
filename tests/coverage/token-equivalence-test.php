<?php

/*
 * Unit tests of tests/coverage/token-equivalence.php, on PHP 8.5 with
 * short_open_tag=0 (the short-tag cases run the command with both settings). Run with sh tests/coverage/token-equivalence.sh
 * --self-test (also runs token-equivalence-test.sh, the Git-level tests;
 * CI: static-analysis job).
 */

require __DIR__ . '/token-equivalence.php';

$failures = 0;

function check($name, $condition)
{
    global $failures;
    echo ($condition ? 'ok   ' : 'FAIL ') . $name . "\n";
    if (!$condition) {
        $failures++;
    }
}

// ---------------------------------------------------------------- compare()
$base = "<?php\nif (\$a) {\n  echo 'x  y';\n}\n?>\n<p>  html\n</p>\n<?php // note  \nfoo( 1,2 );\n";

check('identical sources are equivalent', token_equivalence_compare($base, $base) === null);
check('re-indentation, spacing and blank lines are equivalent', token_equivalence_compare(
    $base,
    "<?php\n\nif (\$a)\n{\n    echo 'x  y';\n}\n?>\n<p>  html\n</p>\n<?php    // note\nfoo(1, 2);\n",
) === null);
check('CRLF code is equivalent to LF code', token_equivalence_compare(
    "<?php\r\n\$a = 1;\r\n// c\r\n",
    "<?php\n\$a = 1;\n// c\n",
    true,
    $crlf,
) === null && $crlf === false);
check('CRLF -> LF in inline HTML is a change by default', token_equivalence_compare(
    "<?php \$a = 1; ?>\r\n<p>\r\n</p>\r\n",
    "<?php \$a = 1; ?>\n<p>\n</p>\n",
) !== null);
check('CRLF -> LF in inline HTML is equivalent with --allow-crlf-to-lf and reported', token_equivalence_compare(
    "<?php \$a = 1; ?>\r\n<p>\r\n</p>\r\n",
    "<?php \$a = 1; ?>\n<p>\n</p>\n",
    true,
    $crlf,
) === null && $crlf === true);
check('CRLF -> LF in a string literal is equivalent with the flag and reported', token_equivalence_compare(
    "<?php echo '<p>\r\n</p>';\r\n",
    "<?php echo '<p>\n</p>';\n",
    true,
    $crlf,
) === null && $crlf === true);
check('LF -> CRLF in inline HTML is a change, even with the flag', token_equivalence_compare(
    "<?php \$a = 1; ?>\n<p>\n</p>\n",
    "<?php \$a = 1; ?>\n<p>\r\n</p>\n",
    true,
) !== null);
check('re-indented inline HTML is a change', token_equivalence_compare(
    $base,
    str_replace("<p>  html", "    <p>  html", $base),
) !== null);
check('trailing blank in inline HTML is a change', token_equivalence_compare(
    "<?php \$a = 1; ?>\n<p>\n",
    "<?php \$a = 1; ?>\n<p> \n",
) !== null);
check('blank inside a string literal is a change', token_equivalence_compare(
    "<?php echo 'x  y';\n",
    "<?php echo 'x y';\n",
) !== null);
check('heredoc content is significant', token_equivalence_compare(
    "<?php echo <<<END\n  a\nEND;\n",
    "<?php echo <<<END\n    a\nEND;\n",
) !== null);
check('heredoc closing-marker indentation is significant', token_equivalence_compare(
    "<?php\necho <<<END\n    a\n    END;\n",
    "<?php\necho <<<END\n    a\nEND;\n",
) !== null);
check('a real code change is a change', token_equivalence_compare(
    "<?php if (\$a == 1) { f(); }\n",
    "<?php if (\$a != 1) { f(); }\n",
) !== null);
check('elseif vs else if is a change', token_equivalence_compare(
    "<?php if (\$a) {} else if (\$b) {}\n",
    "<?php if (\$a) {} elseif (\$b) {}\n",
) !== null);
check('array() vs [] is a change', token_equivalence_compare(
    "<?php \$a = array(1);\n",
    "<?php \$a = [1];\n",
) !== null);
check('removing whitespace that merges tokens is a change', token_equivalence_compare(
    "<?php \$a = \$b - -\$c;\n",
    "<?php \$a = \$b--\$c;\n",
) !== null);
check('the newline swallowed by ?> still counts', token_equivalence_compare(
    "<?php f(); ?>\nx",
    "<?php f(); ?>\n\nx",
) !== null);
check('comment words are significant', token_equivalence_compare(
    "<?php // one two\n",
    "<?php // one  two\n",
) !== null);
check('removed closing tag is a change', token_equivalence_compare(
    "<?php f();\n?>\n",
    "<?php f();\n",
) !== null);

check('a doc comment is compared byte for byte', token_equivalence_compare(
    "<?php\n/**\n * a\n */\nfunction f() {}\n",
    "<?php\n    /**\n     * a\n     */\nfunction f() {}\n",
) !== null);
check('__LINE__ is never equivalent', token_equivalence_compare(
    "<?php echo __LINE__;\n",
    "<?php\n\necho __LINE__;\n",
) !== null && strpos(token_equivalence_compare("<?php echo __LINE__;\n", "<?php echo __LINE__;\n"), '__LINE__') !== false);
check('?> with a bare CR is not read as ?> with LF', token_equivalence_compare(
    "<?php f(); ?>\rx",
    "<?php f(); ?>\nx",
) !== null);
check('?> with CRLF equals ?> with LF (both swallowed)', token_equivalence_compare(
    "<?php f(); ?>\r\nx",
    "<?php f(); ?>\nx",
) === null);
check('blanks inside a cast are a change', token_equivalence_compare(
    "<?php \$a = ( int ) \$b;\n",
    "<?php \$a = (int) \$b;\n",
) !== null);
check('blanks inside yield from are a change', token_equivalence_compare(
    "<?php function g() { yield  from h(); }\n",
    "<?php function g() { yield from h(); }\n",
) !== null);
check('#[ (attribute) vs # [ (comment) is a change', token_equivalence_compare(
    "<?php\n#[A]\nfunction f() {}\n",
    "<?php\n# [A]\nfunction f() {}\n",
) !== null);
check('blanks around <?= are equivalent', token_equivalence_compare(
    "<p><?=\$a?></p>",
    "<p><?= \$a ?></p>",
) === null);

// ---------------------------------------------------------------- runtime
check('PHP 8.4 is refused', token_equivalence_runtime_problem(80499, '0', '0') !== null);
check('a missing tokenizer is refused', token_equivalence_runtime_problem(80500, '0', '0', false) !== null);
check('a missing --short-open-tag is refused', token_equivalence_runtime_problem(80500, '0', null) !== null);
check('short_open_tag=1 is refused when 0 was requested', token_equivalence_runtime_problem(80500, '1', '0') !== null);
check('short_open_tag=On is refused when 0 was requested', token_equivalence_runtime_problem(80600, 'On', '0') !== null);
check('short_open_tag=0 is refused when 1 was requested', token_equivalence_runtime_problem(80500, '', '1') !== null);
check('PHP 8.5 with the requested setting runs', token_equivalence_runtime_problem(80500, '0', '0') === null
    && token_equivalence_runtime_problem(80500, '', '0') === null
    && token_equivalence_runtime_problem(80500, '1', '1') === null);

// The command, as token-equivalence.sh runs it (-n, plus the tokenizer where
// it is a shared extension).
$php = escapeshellarg(PHP_BINARY) . ' -n';
exec("$php -r " . escapeshellarg('exit(function_exists("token_get_all") ? 0 : 1);'), $output, $status);
if ($status !== 0) {
    $php .= ' -d extension=tokenizer';
}
$script = escapeshellarg(__DIR__ . '/token-equivalence.php');
function run_command($php, $script, $short, $arguments)
{
    $output = [];
    exec("$php -d short_open_tag=$short $script $arguments 2>&1", $output, $status);

    return [$status, implode("\n", $output)];
}
list($status, $text) = run_command($php, $script, 1, '--short-open-tag=0 /dev/null /dev/null');
check('the command refuses short_open_tag=1 for --short-open-tag=0 (exit 2)', $status === 2
    && strpos($text, 'refusing to run') !== false);
list($status) = run_command($php, $script, 0, '/dev/null /dev/null');
check('the command refuses to run without --short-open-tag (exit 2)', $status === 2);
list($status) = run_command($php, $script, 0, '--short-open-tag=0 /dev/null /dev/null');
check('the command runs with short_open_tag=0', $status === 0);
list($status) = run_command($php, $script, 1, '--short-open-tag=1 /dev/null /dev/null');
check('the command runs with short_open_tag=1', $status === 0);

// Short tags: a change that is whitespace under one setting can be output
// under the other, so token-equivalence.sh requires both.
$directory = sys_get_temp_dir() . '/pgacc-token-equivalence-' . getmypid();
mkdir($directory);
file_put_contents("$directory/old.php", "<? echo '<?php a  b'; ?>\n");
file_put_contents("$directory/new.php", "<? echo '<?php a b'; ?>\n");
$pair = escapeshellarg("$directory/old.php") . ' ' . escapeshellarg("$directory/new.php");
list($zero) = run_command($php, $script, 0, "--short-open-tag=0 $pair");
list($one) = run_command($php, $script, 1, "--short-open-tag=1 $pair");
check('a string inside a short tag: whitespace with short_open_tag=0, a change with =1', $zero === 0 && $one === 1);
file_put_contents("$directory/old.php", "<? if (\$a) {f();} ?>\n");
file_put_contents("$directory/new.php", "<? if (\$a) { f(); } ?>\n");
list($zero) = run_command($php, $script, 0, "--short-open-tag=0 $pair");
list($one) = run_command($php, $script, 1, "--short-open-tag=1 $pair");
check('code inside a short tag: inline HTML with short_open_tag=0, whitespace with =1', $zero === 1 && $one === 0);
exec('rm -rf ' . escapeshellarg($directory));

if ($failures > 0) {
    echo "$failures token-equivalence self-test(s) failed\n";
    exit(1);
}
echo "token-equivalence self-test passed\n";
