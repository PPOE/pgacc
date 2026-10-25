<?php

/*
 * Proves that a changed PHP file has the same abstract syntax tree as before,
 * apart from source positions, ordinary comments and the long/short spelling
 * of arrays and list() destructuring. array() -> [] and list() -> [] are
 * "equivalent"; any other change is not. A review tool for mechanical syntax
 * changes (tests/coverage/ast-equivalence.sh).
 *
 * Normally run through tests/coverage/ast-equivalence.sh, which collects the
 * changed files from Git, picks a runtime with the pinned nikic/php-parser
 * (tests/ast/composer.lock) and runs this script twice, with
 * short_open_tag=0 and with short_open_tag=1 (the application enables short
 * tags in .htaccess): a file is equivalent only if it is in both. Directly,
 * for one setting N:
 *   php -n -d short_open_tag=N tests/coverage/ast-equivalence.php \
 *       --short-open-tag=N --autoload=<vendor/autoload.php> \
 *       --parser-version=<version> <old-file> <new-file>
 *       exit 0 if equivalent, 1 if not (the first difference is printed)
 *   ... --dir <dir>
 *       <dir>/list names one repository path per line, <dir>/old/<path> and
 *       <dir>/new/<path> hold both versions; prints "<path>: <verdict>" per
 *       file, exit 1 if any differs
 * Exit 2 for anything that keeps the check from running as pinned: PHP older
 * than 8.5, a short_open_tag other than the requested N, an autoloader that
 * cannot be loaded, a php-parser version other than the requested one, an
 * unreadable input, a parse error in either version, an unexpected node or
 * attribute value, or any other error.
 *
 * Equivalence: both versions are parsed for PHP 8.5 and serialized node by
 * node (class, every sub-node, every attribute, in sorted order). Removed
 * before the comparison, and nothing else:
 * - the position attributes (startLine, endLine, startFilePos, endFilePos,
 *   startTokenPos, endTokenPos);
 * - ordinary comments (// # and block comments). Doc comments (/** ... *\/,
 *   visible through getDocComment()) stay byte for byte;
 * - the "kind" attribute of Expr\Array_ and Expr\List_ (array() vs [],
 *   list() vs []).
 * Every other attribute stays, for example the quoting kind and raw value of
 * strings and numbers and the heredoc label and indentation. Inline HTML,
 * string values, the source text of interpolated strings and heredoc/nowdoc
 * bodies are compared byte for byte. A file that uses __LINE__ or
 * __halt_compiler() is never equivalent: line numbers and byte offsets are
 * output there, and a reformat moves them.
 */

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    try {
        exit(ast_equivalence_main(array_slice($argv, 1)));
    } catch (Throwable $error) {
        fwrite(STDERR, 'ast-equivalence.php: ' . get_class($error) . ': ' . $error->getMessage() . "\n");
        exit(2);
    }
}

/*
 * Why this runtime must not be used, or null: PHP older than 8.5 or a
 * short_open_tag setting other than the requested one ($expected: '0' or
 * '1').
 */
function ast_equivalence_runtime_problem($versionId, $shortOpenTag, $expected)
{
    if ($versionId < 80500) {
        return 'needs PHP 8.5 or newer (the application runtime), this is ' . $versionId;
    }
    if ($expected !== '0' && $expected !== '1') {
        return 'needs --short-open-tag=0 or --short-open-tag=1';
    }
    if (filter_var($shortOpenTag, FILTER_VALIDATE_BOOLEAN) !== ($expected === '1')) {
        return "needs short_open_tag=$expected (run php -n -d short_open_tag=$expected)";
    }

    return null;
}

/*
 * Why the loaded php-parser must not be used, or null: not loaded, or not
 * the pinned version ($expected, as in composer.lock, such as "v5.9.0").
 */
function ast_equivalence_parser_problem($expected)
{
    if (!class_exists(PhpParser\ParserFactory::class) || !class_exists(Composer\InstalledVersions::class)) {
        return 'nikic/php-parser is not loaded';
    }
    $installed = Composer\InstalledVersions::getPrettyVersion('nikic/php-parser');
    if ($expected === null || $expected === '' || $installed !== $expected) {
        return 'needs nikic/php-parser ' . var_export($expected, true) . ', loaded ' . var_export($installed, true);
    }

    return null;
}

/*
 * Compares two PHP sources. Returns null if they are equivalent, otherwise a
 * description of the first difference. Throws on a parse error or an
 * unexpected value (the caller exits 2).
 */
function ast_equivalence_compare($old, $new)
{
    $sides = ['old' => $old, 'new' => $new];
    $lines = [];
    foreach ($sides as $side => $source) {
        $lines[$side] = ast_equivalence_serialize($source, $side);
    }
    $count = max(count($lines['old']), count($lines['new']));
    for ($index = 0; $index < $count; $index++) {
        if (!isset($lines['old'][$index])) {
            return sprintf('added at new line %d: %s', $lines['new'][$index][1], ast_equivalence_shorten($lines['new'][$index][0]));
        }
        if (!isset($lines['new'][$index])) {
            return sprintf('removed at old line %d: %s', $lines['old'][$index][1], ast_equivalence_shorten($lines['old'][$index][0]));
        }
        if ($lines['old'][$index][0] !== $lines['new'][$index][0]) {
            return sprintf(
                'old line %d %s, new line %d %s',
                $lines['old'][$index][1],
                ast_equivalence_shorten($lines['old'][$index][0]),
                $lines['new'][$index][1],
                ast_equivalence_shorten($lines['new'][$index][0]),
            );
        }
    }

    return null;
}

/*
 * The normalized tree of $source as a list of [text, source line]. Throws
 * on a parse error, and with "uses __LINE__" / "uses __halt_compiler()"
 * messages through ast_equivalence_compare()'s caller.
 */
function ast_equivalence_serialize($source, $side)
{
    $parser = (new PhpParser\ParserFactory())->createForVersion(PhpParser\PhpVersion::fromComponents(8, 5));
    // Throws PhpParser\Error on the first syntax error.
    $statements = $parser->parse($source);
    if ($statements === null) {
        throw new RuntimeException("the $side file cannot be parsed");
    }
    $lines = [];
    ast_equivalence_dump($statements, 'root', $source, $side, 0, $lines);

    return $lines;
}

// Thrown for a construct that is never equivalent; the verdict names it.
class AstEquivalenceNotProvable extends RuntimeException {}

function ast_equivalence_dump($value, $path, $source, $side, $line, &$lines)
{
    if ($value instanceof PhpParser\Node) {
        if ($value instanceof PhpParser\Node\Scalar\MagicConst\Line) {
            throw new AstEquivalenceNotProvable("the $side file uses __LINE__ (line {$value->getStartLine()}): line numbers are output, not provable");
        }
        if ($value instanceof PhpParser\Node\Stmt\HaltCompiler) {
            throw new AstEquivalenceNotProvable("the $side file uses __halt_compiler() (line {$value->getStartLine()}): byte offsets are output, not provable");
        }
        $line = $value->getStartLine() > 0 ? $value->getStartLine() : $line;
        $lines[] = ["$path: " . get_class($value), $line];
        $attributes = $value->getAttributes();
        $docComments = [];
        foreach ($attributes['comments'] ?? [] as $comment) {
            if (!$comment instanceof PhpParser\Comment) {
                throw new UnexpectedValueException("unexpected comment value at $path");
            }
            if ($comment instanceof PhpParser\Comment\Doc) {
                $docComments[] = $comment->getText();
            }
        }
        unset(
            $attributes['comments'],
            $attributes['startLine'],
            $attributes['endLine'],
            $attributes['startFilePos'],
            $attributes['endFilePos'],
            $attributes['startTokenPos'],
            $attributes['endTokenPos'],
        );
        if ($value instanceof PhpParser\Node\Expr\Array_ || $value instanceof PhpParser\Node\Expr\List_) {
            unset($attributes['kind']);
        }
        if ($docComments !== []) {
            $attributes['docComments'] = $docComments;
        }
        if ($value instanceof PhpParser\Node\Scalar\InterpolatedString) {
            // The exact source text of "...", `...` and heredoc bodies.
            $start = $value->getStartFilePos();
            $end = $value->getEndFilePos();
            if ($start < 0 || $end < $start) {
                throw new UnexpectedValueException("no source position at $path");
            }
            $attributes['sourceText'] = substr($source, $start, $end - $start + 1);
        }
        ksort($attributes, SORT_STRING);
        foreach ($attributes as $name => $attribute) {
            ast_equivalence_dump($attribute, "$path@$name", $source, $side, $line, $lines);
        }
        foreach ($value->getSubNodeNames() as $name) {
            ast_equivalence_dump($value->$name, "$path.$name", $source, $side, $line, $lines);
        }

        return;
    }
    if (is_array($value)) {
        $lines[] = ["$path: array(" . count($value) . ')', $line];
        foreach ($value as $key => $item) {
            ast_equivalence_dump($item, $path . '[' . var_export($key, true) . ']', $source, $side, $line, $lines);
        }

        return;
    }
    if ($value === null || is_scalar($value)) {
        // var_export(): exact bytes and type (1 vs '1' vs 1.0 vs true).
        $lines[] = ["$path = " . var_export($value, true), $line];

        return;
    }
    throw new UnexpectedValueException("unexpected value of type " . get_debug_type($value) . " at $path");
}

function ast_equivalence_shorten($text)
{
    $text = strlen($text) > 160 ? substr($text, 0, 157) . '...' : $text;

    return json_encode($text, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
}

// The verdict for one pair: [equivalent?, message]. Throws on errors.
function ast_equivalence_verdict($old, $new)
{
    try {
        $difference = ast_equivalence_compare($old, $new);
    } catch (AstEquivalenceNotProvable $reason) {
        return [false, 'not provable: ' . $reason->getMessage()];
    }
    if ($difference !== null) {
        return [false, "AST change: $difference"];
    }

    return [true, 'equivalent'];
}

function ast_equivalence_main($arguments)
{
    $options = ['short-open-tag' => null, 'autoload' => null, 'parser-version' => null];
    while (isset($arguments[0]) && preg_match('/^--(short-open-tag|autoload|parser-version)=(.*)$/s', $arguments[0], $match)) {
        $options[$match[1]] = $match[2];
        array_shift($arguments);
    }
    $problem = ast_equivalence_runtime_problem(PHP_VERSION_ID, ini_get('short_open_tag'), $options['short-open-tag']);
    if ($problem === null) {
        if ($options['autoload'] === null || !is_file($options['autoload'])) {
            $problem = 'needs --autoload=<vendor/autoload.php of tests/ast>';
        } else {
            require_once $options['autoload'];
            $problem = ast_equivalence_parser_problem($options['parser-version']);
        }
    }
    if ($problem !== null) {
        fwrite(STDERR, "ast-equivalence.php: refusing to run: $problem\n");

        return 2;
    }
    if (count($arguments) === 2 && $arguments[0] === '--dir') {
        $dir = $arguments[1];
        $list = @file_get_contents("$dir/list");
        if ($list === false) {
            fwrite(STDERR, "Cannot read $dir/list\n");

            return 2;
        }
        $failed = false;
        foreach (preg_split('/\n/', $list, -1, PREG_SPLIT_NO_EMPTY) as $path) {
            $old = @file_get_contents("$dir/old/$path");
            $new = @file_get_contents("$dir/new/$path");
            if ($old === false || $new === false) {
                fwrite(STDERR, "Cannot read both versions of $path\n");

                return 2;
            }
            [$equivalent, $message] = ast_equivalence_verdict($old, $new);
            echo "$path: $message\n";
            $failed = $failed || !$equivalent;
        }

        return $failed ? 1 : 0;
    }
    if (count($arguments) === 2 && !str_starts_with($arguments[0], '--')) {
        $old = @file_get_contents($arguments[0]);
        $new = @file_get_contents($arguments[1]);
        if ($old === false || $new === false) {
            fwrite(STDERR, "Cannot read {$arguments[0]} or {$arguments[1]}\n");

            return 2;
        }
        [$equivalent, $message] = ast_equivalence_verdict($old, $new);
        echo "$message\n";

        return $equivalent ? 0 : 1;
    }
    fwrite(STDERR, "Usage: php -n -d short_open_tag=N tests/coverage/ast-equivalence.php --short-open-tag=N --autoload=<path> --parser-version=<version> <old-file> <new-file>|--dir <dir>\n");

    return 2;
}
