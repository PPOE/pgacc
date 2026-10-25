<?php

/*
 * Proves that a changed PHP file has the same token stream as before, apart
 * from whitespace that cannot change behaviour or output. A whitespace-only
 * change (re-indentation, brace placement, spacing, blank lines, trailing
 * whitespace, line endings of the code) is "equivalent". A standalone review
 * tool (tests/coverage/token-equivalence.sh): no gate uses it.
 *
 * Normally run through tests/coverage/token-equivalence.sh, which collects
 * the changed files from Git, picks a PHP 8.5 runtime and runs this script
 * twice, with short_open_tag=0 and with short_open_tag=1 (the application
 * enables short tags in .htaccess): a file is equivalent only if it is in
 * both. Directly, for one setting N:
 *   php -n -d short_open_tag=N tests/coverage/token-equivalence.php \
 *       --short-open-tag=N [--allow-crlf-to-lf] <old-file> <new-file>
 *       exit 0 if equivalent, 1 if not (the first difference is printed)
 *   php -n -d short_open_tag=N tests/coverage/token-equivalence.php \
 *       --short-open-tag=N [--allow-crlf-to-lf] --dir <dir>
 *       <dir>/list names one repository path per line, <dir>/old/<path> and
 *       <dir>/new/<path> hold both versions; prints "<path>: <verdict>" per
 *       file, exit 1 if any differs
 * Exit 2: the runtime cannot be trusted (it refuses to run unless PHP is
 * 8.5+ with the tokenizer and short_open_tag is the requested N) or the
 * input is unreadable.
 *
 * Equivalence, token by token (token_get_all(), PHP's own tokenizer):
 * - T_WHITESPACE tokens are ignored.
 * - The whitespace that <?php and ?> carry (a single blank or newline after
 *   "<?php", the newline that "?>" swallows) is ignored: PHP never outputs it.
 *   Whether "?>" swallows a newline still counts, as the next T_INLINE_HTML
 *   token shows.
 * - Comments (T_COMMENT) are compared with the leading and trailing blanks
 *   of each line removed and CRLF read as LF. Their words must be identical.
 *   Doc comments (T_DOC_COMMENT, visible through getDocComment()) are
 *   compared byte for byte.
 * - A file that uses __LINE__ (T_LINE) is never equivalent: its output
 *   depends on the line numbers that a whitespace change moves.
 * - Everything else is compared byte for byte, in particular T_INLINE_HTML
 *   (page output), T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE,
 *   heredoc/nowdoc markers and content, casts and keywords.
 * - --allow-crlf-to-lf (for line-ending conversions such as the one of the
 *   formatting pull request; see token-equivalence.sh): a CRLF in an
 *   old token may be an LF in the new one (never the reverse). The verdict
 *   names such files. Without the flag that is a token change.
 */

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(token_equivalence_main(array_slice($argv, 1)));
}

/*
 * Why this runtime must not be used, or null: PHP older than 8.5, no
 * tokenizer, or a short_open_tag setting other than the requested one
 * ($expected: '0' or '1').
 */
function token_equivalence_runtime_problem($versionId, $shortOpenTag, $expected, $tokenizer = true)
{
    if ($versionId < 80500) {
        return 'needs PHP 8.5 or newer (the application runtime), this is ' . $versionId;
    }
    if (!$tokenizer) {
        return 'needs the tokenizer extension (with -n: -d extension=tokenizer)';
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
 * Compares two PHP sources. Returns null if they are equivalent, otherwise a
 * description of the first difference. With $allowCrlfToLf, a CRLF in an old
 * token may be an LF in the new one; $crlfToLf is then set to true if
 * equivalence needed that.
 */
function token_equivalence_compare($old, $new, $allowCrlfToLf = false, &$crlfToLf = null)
{
    $crlfToLf = false;
    $oldTokens = token_equivalence_normalize($old);
    $newTokens = token_equivalence_normalize($new);
    foreach (['old' => $oldTokens, 'new' => $newTokens] as $side => $tokens) {
        foreach ($tokens as $token) {
            if ($token[0] === 'T_LINE') {
                return "the $side file uses __LINE__ (line $token[2]): line numbers are output, not provable";
            }
        }
    }
    $count = max(count($oldTokens), count($newTokens));
    for ($index = 0; $index < $count; $index++) {
        if (!isset($oldTokens[$index])) {
            return sprintf('new token %s at new line %d', token_equivalence_describe($newTokens[$index]), $newTokens[$index][2]);
        }
        if (!isset($newTokens[$index])) {
            return sprintf('token %s removed (old line %d)', token_equivalence_describe($oldTokens[$index]), $oldTokens[$index][2]);
        }
        $a = $oldTokens[$index];
        $b = $newTokens[$index];
        if ($a[0] === $b[0] && $a[1] === $b[1]) {
            continue;
        }
        if ($allowCrlfToLf && $a[0] === $b[0] && str_replace("\r\n", "\n", $a[1]) === $b[1]) {
            $crlfToLf = true;
            continue;
        }

        return sprintf(
            'old line %d %s, new line %d %s',
            $a[2],
            token_equivalence_describe($a),
            $b[2],
            token_equivalence_describe($b),
        );
    }

    return null;
}

// The significant tokens of $source: list of array(name, compared text, line).
function token_equivalence_normalize($source)
{
    $tokens = [];
    $line = 1;
    foreach (token_get_all($source) as $token) {
        if (!is_array($token)) {
            $tokens[] = ['char', $token, $line];
            continue;
        }
        list($id, $text, $line) = $token;
        switch ($id) {
            case T_WHITESPACE:
                continue 2;
            case T_OPEN_TAG:
                $text = rtrim($text, " \t\r\n");
                break;
            case T_CLOSE_TAG:
                $text = str_replace("\r\n", "\n", $text);
                break;
            case T_COMMENT:
                $text = preg_replace('/^[ \t]+|[ \t]+$/m', '', str_replace("\r\n", "\n", $text));
                break;
        }
        $tokens[] = [token_name($id), $text, $line];
    }

    return $tokens;
}

function token_equivalence_describe($token)
{
    $text = strlen($token[1]) > 60 ? substr($token[1], 0, 57) . '...' : $token[1];

    return $token[0] . ' ' . json_encode($text, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
}

// The verdict for one pair: array(equivalent?, message).
function token_equivalence_verdict($old, $new, $allowCrlfToLf)
{
    $difference = token_equivalence_compare($old, $new, $allowCrlfToLf, $crlfToLf);
    if ($difference !== null) {
        return [false, "token change: $difference"];
    }

    return [true, $crlfToLf ? 'equivalent (CRLF -> LF in output tokens)' : 'equivalent'];
}

function token_equivalence_main($arguments)
{
    $expected = null;
    if (isset($arguments[0]) && strpos($arguments[0], '--short-open-tag=') === 0) {
        $expected = substr(array_shift($arguments), strlen('--short-open-tag='));
    }
    $problem = token_equivalence_runtime_problem(
        PHP_VERSION_ID,
        ini_get('short_open_tag'),
        $expected,
        function_exists('token_get_all'),
    );
    if ($problem !== null) {
        fwrite(STDERR, "token-equivalence.php: refusing to run: $problem\n");

        return 2;
    }
    $allow = false;
    if (isset($arguments[0]) && $arguments[0] === '--allow-crlf-to-lf') {
        $allow = true;
        array_shift($arguments);
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
            list($equivalent, $message) = token_equivalence_verdict($old, $new, $allow);
            echo "$path: $message\n";
            $failed = $failed || !$equivalent;
        }

        return $failed ? 1 : 0;
    }
    if (count($arguments) === 2 && substr($arguments[0], 0, 2) !== '--') {
        $old = @file_get_contents($arguments[0]);
        $new = @file_get_contents($arguments[1]);
        if ($old === false || $new === false) {
            fwrite(STDERR, "Cannot read {$arguments[0]} or {$arguments[1]}\n");

            return 2;
        }
        list($equivalent, $message) = token_equivalence_verdict($old, $new, $allow);
        echo "$message\n";

        return $equivalent ? 0 : 1;
    }
    fwrite(STDERR, "Usage: php -n -d short_open_tag=N tests/coverage/token-equivalence.php --short-open-tag=N [--allow-crlf-to-lf] <old-file> <new-file>|--dir <dir>\n");

    return 2;
}
