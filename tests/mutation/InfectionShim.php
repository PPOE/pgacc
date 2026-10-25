<?php

/**
 * Class wrapper for global functions, used only by Infection.
 *
 * Infection (checked through 0.35.8) only mutates code inside classes; global
 * functions such as those in functions.php are never eligible. This class
 * writes a disposable, line-aligned copy of functions.php for Infection:
 *
 * - every top-level `function name(` becomes `public static function name(`
 *   of one final class, on the same line as in functions.php;
 * - after the class, each original global function is redefined as a
 *   one-line delegate that forwards its arguments to the class method.
 *
 * Tests and helpers still call the global names, so behavior, defaults,
 * `global` statements and output are unchanged; reported line numbers match
 * functions.php. Input that cannot be wrapped faithfully is refused with a
 * RuntimeException. The repository is never modified.
 *
 * It is itself a class so that Infection can mutate it
 * (tests/unit/InfectionShimTest.php, `infection.sh 8.5 shim`).
 */
final class PgaccInfectionShim
{
    public const CLASS_NAME = 'PgaccFunctionsMutationShim';

    /**
     * Command line entry: wrap <functions.php> into <output dir>/functions.php.
     */
    public static function main(array $arguments)
    {
        if (count($arguments) !== 3) {
            throw new InvalidArgumentException('Usage: php infection-shim.php <functions.php> <output directory>');
        }
        $source = $arguments[1];
        $output = $arguments[2];
        $code = @file_get_contents($source);
        if ($code === false) {
            throw new RuntimeException('Cannot read ' . $source);
        }
        $wrapped = self::wrap($code, $source);
        if (!is_dir($output) && !@mkdir($output, 0777, true)) {
            throw new RuntimeException('Cannot create ' . $output);
        }
        if (@file_put_contents($output . '/functions.php', $wrapped) !== strlen($wrapped)) {
            throw new RuntimeException('Cannot write ' . $output . '/functions.php');
        }
        printf("Wrapped %d functions from %s for Infection\n", count(self::functionNames($wrapped)), $source);
    }

    /**
     * Returns the wrapped code; throws RuntimeException for unsupported input.
     */
    public static function wrap($code, $source = 'input')
    {
        $class = self::CLASS_NAME;
        $result = '';
        $depth = 0;
        $functions = [];
        $opened = false;
        $closed = false;
        $expectName = false;
        $inSignature = false;

        foreach (token_get_all($code) as $token) {
            $id = is_array($token) ? $token[0] : null;
            $text = is_array($token) ? $token[1] : $token;

            if ($closed && $id !== T_INLINE_HTML) {
                throw new RuntimeException('Code after the closing tag is not supported');
            }
            if ($depth === 0 && !$expectName && !$inSignature) {
                if ($id === T_OPEN_TAG && !$opened) {
                    $opened = true;
                    $tag = rtrim($text, "\r\n");
                    $result .= $tag . " final class $class { " . substr($text, strlen($tag));
                    continue;
                }
                if ($id === T_CLOSE_TAG) {
                    $closed = true;
                    $result .= "}\n";
                    continue;
                }
                if ($id === T_FUNCTION) {
                    $expectName = true;
                    $result .= 'public static function';
                    continue;
                }
                if (!in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) {
                    throw new RuntimeException('Unsupported top-level code: ' . $text);
                }
                if ($id === T_INLINE_HTML && trim($text) !== '') {
                    throw new RuntimeException('Top-level inline HTML is not supported');
                }
            }
            if ($expectName && $id === T_STRING) {
                $functions[] = $text;
                $expectName = false;
                $inSignature = true;
            }
            if (($expectName || $inSignature) && $text === '&') {
                throw new RuntimeException('By-reference parameters or returns are not supported');
            }
            if ($text === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $inSignature = false;
                $depth++;
            } elseif ($text === '}') {
                $depth--;
            }
            if (in_array($text, ['__FUNCTION__', '__METHOD__', '__CLASS__', 'func_get_args', 'func_get_arg', 'func_num_args'], true)
                || $id === T_STATIC) {
                throw new RuntimeException('Scope-sensitive construct is not supported: ' . $text);
            }
            $result .= $text;
        }

        if (!$opened || $depth !== 0 || $expectName || $inSignature || $functions === []) {
            throw new RuntimeException('Could not wrap ' . $source);
        }
        if (!$closed) {
            $result .= "\n}\n";
        }
        foreach ($functions as $function) {
            $result .= "function $function(...\$arguments) { return $class::$function(...\$arguments); }\n";
        }
        return $result;
    }

    /**
     * Names of the global delegates in wrapped code, in declaration order.
     */
    public static function functionNames($wrapped)
    {
        preg_match_all('/^function (\w+)\(\.\.\.\$arguments\) \{ return /m', $wrapped, $matches);
        return $matches[1];
    }
}
