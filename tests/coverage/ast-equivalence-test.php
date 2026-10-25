<?php

/*
 * Unit tests of tests/coverage/ast-equivalence.php, on PHP 8.5 with the
 * pinned php-parser. Run with sh tests/coverage/ast-equivalence.sh
 * --self-test, once with short_open_tag=0 and once with =1 (it also runs
 * ast-equivalence-test.sh, the Git-level tests). Arguments: the
 * --short-open-tag=, --autoload= and --parser-version= options of
 * ast-equivalence.php.
 */

require __DIR__ . '/ast-equivalence.php';

$failures = 0;

function check($name, $condition)
{
    global $failures;
    echo ($condition ? 'ok   ' : 'FAIL ') . $name . "\n";
    if (!$condition) {
        $failures++;
    }
}

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--(short-open-tag|autoload|parser-version)=(.*)$/s', $argument, $match)) {
        $options[$match[1]] = $match[2];
    }
}
if (!isset($options['short-open-tag'], $options['autoload'], $options['parser-version'])) {
    fwrite(STDERR, "ast-equivalence-test.php: needs --short-open-tag=, --autoload= and --parser-version=\n");
    exit(2);
}
$problem = ast_equivalence_runtime_problem(PHP_VERSION_ID, ini_get('short_open_tag'), $options['short-open-tag']);
if ($problem !== null) {
    fwrite(STDERR, "ast-equivalence-test.php: $problem\n");
    exit(2);
}
require_once $options['autoload'];
$short = $options['short-open-tag'];
echo "short_open_tag=$short\n";

function equivalent($old, $new)
{
    return ast_equivalence_verdict($old, $new) === [true, 'equivalent'];
}

function verdict($old, $new)
{
    return ast_equivalence_verdict($old, $new)[1];
}

// ------------------------------------------------------------ runtime guards
check('PHP older than 8.5 is refused', ast_equivalence_runtime_problem(80499, '0', '0') !== null);
check('a short_open_tag other than the requested one is refused', ast_equivalence_runtime_problem(80500, '1', '0') !== null
    && ast_equivalence_runtime_problem(80500, '', '1') !== null);
check('a missing --short-open-tag is refused', ast_equivalence_runtime_problem(80500, '0', null) !== null);
check('the requested runtime is accepted', ast_equivalence_runtime_problem(80500, 'On', '1') === null
    && ast_equivalence_runtime_problem(80500, '0', '0') === null);
check('the pinned parser version is accepted', ast_equivalence_parser_problem($options['parser-version']) === null);
check('another parser version is refused', ast_equivalence_parser_problem('v0.0.1') !== null
    && ast_equivalence_parser_problem(null) !== null && ast_equivalence_parser_problem('') !== null);

// ------------------------------------------------------- equivalent changes
$base = "<?php\n\$a = array(1, 'k' => array(2, 3));\nlist(\$x, \$y) = f(\$a);\n?>\n<p>html</p>\n";
check('identical sources are equivalent', equivalent($base, $base));
check('array() vs [] is equivalent', equivalent(
    "<?php\n\$a = array(1, 'k' => array(2, 3), );\nf(array());\n",
    "<?php\n\$a = [1, 'k' => [2, 3]];\nf([]);\n",
));
check('list() vs [] destructuring is equivalent', equivalent(
    "<?php\nlist(\$a, list(, \$b)) = f();\nforeach (\$x as list('k' => \$c)) {}\n",
    "<?php\n[\$a, [, \$b]] = f();\nforeach (\$x as ['k' => \$c]) {}\n",
));
check('re-indentation, line breaks and ordinary comments are equivalent', equivalent(
    "<?php\nif (\$a) { // why\n  f(array(1,\n 2)); /* note */ g();\n}\n",
    "<?php\nif (\$a) {\n    # other words\n    f([1, 2]);\n    g();\n}\n",
));

// --------------------------------------------------- non-equivalent changes
check('a changed string is not equivalent', !equivalent(
    "<?php\necho 'a  b';\n",
    "<?php\necho 'a b';\n",
));
check('a string quote change is not equivalent (kind and raw value stay)', !equivalent(
    "<?php\necho 'ab';\n",
    "<?php\necho \"ab\";\n",
));
check('a changed number spelling is not equivalent', !equivalent("<?php\n\$a = 10;\n", "<?php\n\$a = 0xA;\n"));
check('changed inline HTML is not equivalent', !equivalent(
    "<?php \$a = 1; ?>\n<p>\n  html\n</p>\n",
    "<?php \$a = 1; ?>\n<p>\n    html\n</p>\n",
));
check('a newline swallowed by ?> or not is not equivalent', !equivalent(
    "<?php \$a = 1; ?>\n<p></p>",
    "<?php \$a = 1; ?>\n\n<p></p>",
));
check('a changed operator is not equivalent', !equivalent("<?php\n\$a = \$b == \$c;\n", "<?php\n\$a = \$b === \$c;\n"));
check('a reordered statement is not equivalent', !equivalent(
    "<?php\nf();\ng();\n",
    "<?php\ng();\nf();\n",
));
check('a changed heredoc body is not equivalent', !equivalent(
    "<?php\necho <<<EOT\n  a {\$b}  c\n  EOT;\n",
    "<?php\necho <<<EOT\n  a {\$b} c\n  EOT;\n",
));
check('a changed heredoc indentation is not equivalent', !equivalent(
    "<?php\necho <<<EOT\n  a\n  EOT;\n",
    "<?php\necho <<<EOT\n    a\n    EOT;\n",
));
check('a changed interpolation spelling is not equivalent', !equivalent(
    "<?php\necho \"a \$b c\";\n",
    "<?php\necho \"a {\$b} c\";\n",
));
check('a changed doc comment is not equivalent', !equivalent(
    "<?php\n/** @var int */\nfunction f() {}\n",
    "<?php\n/**  @var int */\nfunction f() {}\n",
));
check('a removed doc comment is not equivalent', !equivalent(
    "<?php\n/** doc */\nfunction f() {}\n",
    "<?php\nfunction f() {}\n",
));
check('a cast spelling change is not equivalent', !equivalent("<?php\n\$a = (integer) \$b;\n", "<?php\n\$a = (int) \$b;\n"));
check('an added __LINE__ is not provable', str_starts_with(verdict(
    "<?php\n\$a = array(1);\n",
    "<?php\n\$a = [__LINE__];\n",
), 'not provable: the new file uses __LINE__'));
check('a file with __LINE__ is never equivalent, even unchanged', !equivalent(
    "<?php\necho __LINE__;\n",
    "<?php\necho __LINE__;\n",
));
check('a file with __halt_compiler() is never equivalent', !equivalent(
    "<?php\nf(array());\n__halt_compiler();data",
    "<?php\nf([]);\n__halt_compiler();data",
));
check('array() vs [] inside a string is a string change', !equivalent(
    "<?php\necho 'array()';\n",
    "<?php\necho '[]';\n",
));

// ----------------------------------------------------------- short open tags
$shortPage = "<? echo array(1)[0] . 'x  y'; ?>\n";
if ($short === '1') {
    check('short tag code (short_open_tag=1): array() vs [] is equivalent', equivalent($shortPage, "<? echo [1][0] . 'x  y'; ?>\n"));
    check('short tag code (short_open_tag=1): a string change is not', !equivalent($shortPage, "<? echo [1][0] . 'x y'; ?>\n"));
} else {
    check('short tag code (short_open_tag=0) is inline HTML: array() vs [] is a change', !equivalent($shortPage, "<? echo [1][0] . 'x  y'; ?>\n"));
}

// -------------------------------------------------------------------- errors
$threw = false;
try {
    ast_equivalence_verdict("<?php\nf(;\n", "<?php\nf();\n");
} catch (PhpParser\Error $error) {
    $threw = true;
}
check('a parse error throws (exit 2 in main)', $threw);

// The command line: exit codes and refusals.
$php = escapeshellarg(PHP_BINARY);
$script = escapeshellarg(__DIR__ . '/ast-equivalence.php');
$directory = sys_get_temp_dir() . '/pgacc-ast-equivalence-' . getmypid();
mkdir($directory);
$good = '--short-open-tag=' . $short . ' --autoload=' . escapeshellarg($options['autoload'])
    . ' --parser-version=' . escapeshellarg($options['parser-version']);
function run_command($command)
{
    exec($command . ' 2>&1', $output, $status);

    return [$status, implode("\n", $output)];
}
$pair = escapeshellarg("$directory/old.php") . ' ' . escapeshellarg("$directory/new.php");
$run = "$php -n -d short_open_tag=$short $script";
file_put_contents("$directory/old.php", "<?php\n\$a = array(1);\n");
file_put_contents("$directory/new.php", "<?php\n\$a = [1];\n");
check('command: equivalent is exit 0', run_command("$run $good $pair")[0] === 0);
file_put_contents("$directory/new.php", "<?php\n\$a = [2];\n");
check('command: a change is exit 1', run_command("$run $good $pair")[0] === 1);
file_put_contents("$directory/new.php", "<?php\n\$a = [;\n");
check('command: a parse error is exit 2', run_command("$run $good $pair")[0] === 2);
file_put_contents("$directory/new.php", "<?php\n\$a = [1];\n");
check('command: another parser version is exit 2', run_command("$run --short-open-tag=$short --autoload="
    . escapeshellarg($options['autoload']) . " --parser-version=v0.0.1 $pair")[0] === 2);
check('command: no autoloader is exit 2', run_command("$run --short-open-tag=$short --parser-version="
    . escapeshellarg($options['parser-version']) . " $pair")[0] === 2);
$other = $short === '1' ? '0' : '1';
check('command: a mismatched short_open_tag is exit 2', run_command("$php -n -d short_open_tag=$other $script $good $pair")[0] === 2);
check('command: a missing file is exit 2', run_command("$run $good " . escapeshellarg("$directory/none.php") . ' '
    . escapeshellarg("$directory/new.php"))[0] === 2);
check('command: a usage error is exit 2', run_command("$run $good")[0] === 2);
mkdir("$directory/old");
mkdir("$directory/new");
file_put_contents("$directory/list", "a.php\nb.php\n");
file_put_contents("$directory/old/a.php", "<?php\nf(array());\n");
file_put_contents("$directory/new/a.php", "<?php\nf([]);\n");
file_put_contents("$directory/old/b.php", "<?php\nf(1);\n");
file_put_contents("$directory/new/b.php", "<?php\nf(2);\n");
[$status, $output] = run_command("$run $good --dir " . escapeshellarg($directory));
check('command --dir: one verdict per file, exit 1 if any differs', $status === 1
    && str_contains($output, "a.php: equivalent\n") && str_contains($output, 'b.php: AST change: '));
file_put_contents("$directory/list", "a.php\nmissing.php\n");
check('command --dir: an unreadable file is exit 2', run_command("$run $good --dir " . escapeshellarg($directory))[0] === 2);
exec('rm -rf ' . escapeshellarg($directory));

if ($failures > 0) {
    echo "$failures ast-equivalence self-test(s) failed\n";
    exit(1);
}
echo "ast-equivalence self-test passed\n";
