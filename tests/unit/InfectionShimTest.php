<?php

require_once dirname(__DIR__) . '/mutation/InfectionShim.php';

// Tests for the Infection class wrapper (tests/mutation/InfectionShim.php).
// PHP 5.6-compatible: no setUp()/tearDown(), because PHPUnit 9 requires a
// `void` return type that PHP 5.6 cannot parse.
final class InfectionShimTest extends PHPUnit\Framework\TestCase
{
    const SHIM = 'PgaccFunctionsMutationShim';

    public function testWrapsMinimalInputExactly()
    {
        self::assertSame(
            "<?php final class PgaccFunctionsMutationShim { \n"
            . "public static function a(\$x = 1)\n{\n  return \$x;\n}\n"
            . "\n}\n"
            . "function a(...\$arguments) { return PgaccFunctionsMutationShim::a(...\$arguments); }\n",
            PgaccInfectionShim::wrap("<?php\nfunction a(\$x = 1)\n{\n  return \$x;\n}\n")
        );
    }

    public function testReplacesClosingTagAndKeepsLineEndings()
    {
        self::assertSame(
            "<?php final class PgaccFunctionsMutationShim { \r\n"
            . "// c\r\n/** d */\r\npublic static function a() {}\r\n"
            . "public static function b() { return \"{\$x}\${y}\"; }\r\n"
            . "}\n\n"
            . "function a(...\$arguments) { return PgaccFunctionsMutationShim::a(...\$arguments); }\n"
            . "function b(...\$arguments) { return PgaccFunctionsMutationShim::b(...\$arguments); }\n",
            PgaccInfectionShim::wrap("<?php\r\n// c\r\n/** d */\r\nfunction a() {}\r\n"
                . "function b() { return \"{\$x}\${y}\"; }\r\n?>\n\n")
        );
    }

    public function testKeepsWhitespaceBeforeOpeningTagAndAfterInlineOpeningTag()
    {
        self::assertSame(
            "\n<?php  final class PgaccFunctionsMutationShim { public static function a() {}\n}\n"
            . "function a(...\$arguments) { return PgaccFunctionsMutationShim::a(...\$arguments); }\n",
            PgaccInfectionShim::wrap("\n<?php function a() {}")
        );
    }

    public function testFixtureFunctionsKeepTheirLines()
    {
        $original = file_get_contents(self::fixture());
        $wrapped = PgaccInfectionShim::wrap($original);

        $lines = self::functionLines($original);
        self::assertSame(
            array('shim_fixture_greet' => 8, 'shim_fixture_sum' => 15, 'shim_fixture_nothing' => 24),
            $lines
        );
        self::assertSame($lines, self::functionLines($wrapped));
        self::assertSame(array_keys($lines), PgaccInfectionShim::functionNames($wrapped));
        $originalLines = explode("\n", $original);
        $wrappedLines = explode("\n", $wrapped);
        self::assertSame('<?php final class ' . self::SHIM . ' { ', $wrappedLines[0]);
        for ($line = 1; $line < 24; $line++) {
            self::assertSame($originalLines[$line], str_replace('public static function', 'function', $wrappedLines[$line]));
        }
        self::assertSame(array('?>', ''), array_slice($originalLines, 24));
        self::assertSame('}', $wrappedLines[24]);
        self::assertSame(29, count($wrappedLines));
    }

    public function testFunctionsPhpKeepsEveryFunctionOnItsLine()
    {
        $original = file_get_contents(dirname(dirname(__DIR__)) . '/functions.php');
        $wrapped = PgaccInfectionShim::wrap($original);
        $lines = self::functionLines($original);

        self::assertGreaterThan(15, count($lines));
        self::assertSame($lines, self::functionLines($wrapped));
        self::assertSame(array_keys($lines), PgaccInfectionShim::functionNames($wrapped));
        self::assertSame(
            substr_count($original, "\n") + count($lines),
            substr_count($wrapped, "\n")
        );
        list($status, $output) = self::php(array('-l', self::write($wrapped)));
        self::assertSame(0, $status, $output);
    }

    public function testWrappedFixtureBehavesLikeTheOriginal()
    {
        $driver = self::write(
            "<?php\nrequire \$argv[1];\n\$shim_fixture_prefix = '> ';\n"
            . "var_export(class_exists('" . self::SHIM . "', false));\necho \"\\n\";\n"
            . "var_export(array(shim_fixture_greet(), shim_fixture_greet('PHP', '?'),\n"
            . "  shim_fixture_sum(array(1, -2, 3)), shim_fixture_sum(array(), 5), shim_fixture_nothing()));\n"
        );
        $wrapped = self::write(PgaccInfectionShim::wrap(file_get_contents(self::fixture())));

        $results = "> Hello, world!\n> Hello, PHP?\n" . var_export(array(5, 3,
            array(4, array(2, -4, 6)), array(5, array()), null), true);

        list($status, $output) = self::php(array($driver, self::fixture()));
        self::assertSame(0, $status, $output);
        self::assertSame("false\n" . $results, $output);

        list($status, $output) = self::php(array($driver, $wrapped));
        self::assertSame(0, $status, $output);
        self::assertSame("true\n" . $results, $output);
    }

    /**
     * @dataProvider refusalProvider
     */
    public function testRefusesCodeItCannotWrapFaithfully($code, $message)
    {
        try {
            PgaccInfectionShim::wrap($code, 'x.php');
            self::fail('No exception for ' . $code);
        } catch (RuntimeException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    public static function refusalProvider()
    {
        $scope = 'Scope-sensitive construct is not supported: ';
        $reference = 'By-reference parameters or returns are not supported';
        return array(
            'statement' => array("<?php\n\$x = 1;\nfunction a() {}\n", 'Unsupported top-level code: $x'),
            'call' => array("<?php\nfunction a() {}\na();\n", 'Unsupported top-level code: a'),
            'class' => array("<?php\nclass A {}\n", 'Unsupported top-level code: class'),
            'leading HTML' => array("#!/usr/bin/env php\n<?php\nfunction a() {}\n", 'Top-level inline HTML is not supported'),
            'trailing HTML' => array("<?php\nfunction a() {}\n?>\nhtml", 'Top-level inline HTML is not supported'),
            'second PHP block' => array("<?php\nfunction a() {}\n?>\n<?php\nfunction b() {}\n", 'Code after the closing tag is not supported'),
            'by-reference parameter' => array("<?php\nfunction a(\$b, &\$c) {}\n", $reference),
            'by-reference return' => array("<?php\nfunction &a() {}\n", $reference),
            'static in signature' => array("<?php\nfunction a(): static {}\n", $scope . 'static'),
            'top-level static' => array("<?php\nstatic \$x;\nfunction a() {}\n", 'Unsupported top-level code: static'),
            'static variable' => array("<?php\nfunction a() { if (true) { static \$calls = 0; } }\n", $scope . 'static'),
            '__FUNCTION__' => array("<?php\nfunction a() { return __FUNCTION__; }\n", $scope . '__FUNCTION__'),
            '__METHOD__' => array("<?php\nfunction a() { return __METHOD__; }\n", $scope . '__METHOD__'),
            '__CLASS__' => array("<?php\nfunction a() { return __CLASS__; }\n", $scope . '__CLASS__'),
            'func_get_args' => array("<?php\nfunction a() { return func_get_args(); }\n", $scope . 'func_get_args'),
            'func_get_arg' => array("<?php\nfunction a() { return func_get_arg(0); }\n", $scope . 'func_get_arg'),
            'func_num_args' => array("<?php\nfunction a() { return func_num_args(); }\n", $scope . 'func_num_args'),
            'empty' => array('', 'Could not wrap x.php'),
            'whitespace only' => array(" \n", 'Could not wrap x.php'),
            'no functions' => array("<?php\n// none\n", 'Could not wrap x.php'),
            'unclosed body' => array("<?php\nfunction a() {\n", 'Could not wrap x.php'),
            'missing name' => array("<?php\nfunction", 'Could not wrap x.php'),
            'missing body' => array("<?php\nfunction a()", 'Could not wrap x.php'),
        );
    }

    public function testDefaultSourceNameInMessages()
    {
        try {
            PgaccInfectionShim::wrap('');
            self::fail('No exception');
        } catch (RuntimeException $exception) {
            self::assertSame('Could not wrap input', $exception->getMessage());
        }
    }

    public function testMainWritesWrappedCopyIntoNewDirectory()
    {
        $output = self::temporaryPath() . '/a/b';
        ob_start();
        $result = PgaccInfectionShim::main(array('infection-shim.php', self::fixture(), $output));
        self::assertSame('Wrapped 3 functions from ' . self::fixture() . " for Infection\n", ob_get_clean());
        self::assertNull($result);
        self::assertSame(
            PgaccInfectionShim::wrap(file_get_contents(self::fixture())),
            file_get_contents($output . '/functions.php')
        );
        self::assertSame(array('.', '..', 'functions.php'), scandir($output));
        self::assertSame(0777 & ~umask(), fileperms($output) & 0777);
        self::assertSame(0777 & ~umask(), fileperms(dirname($output)) & 0777);
    }

    public function testCommandLineEntryUsesExistingDirectory()
    {
        $output = self::temporaryPath();
        mkdir($output);
        file_put_contents($output . '/functions.php', 'old');
        $argv = array('infection-shim.php', self::fixture(), $output);
        ob_start();
        include dirname(__DIR__) . '/mutation/infection-shim.php';
        self::assertSame('Wrapped 3 functions from ' . self::fixture() . " for Infection\n", ob_get_clean());
        self::assertSame(
            PgaccInfectionShim::wrap(file_get_contents(self::fixture())),
            file_get_contents($output . '/functions.php')
        );
    }

    public function testCommandLineExitStatus()
    {
        $script = dirname(__DIR__) . '/mutation/infection-shim.php';
        list($status, $output) = self::php(array($script, self::fixture(), self::temporaryPath()));
        self::assertSame(0, $status, $output);
        self::assertStringEndsWith(" for Infection\n", $output);

        list($status, $output) = self::php(array($script, self::write("<?php\n\$x = 1;\n"), self::temporaryPath()));
        self::assertNotSame(0, $status, $output);
        self::assertTrue(strpos($output, 'Unsupported top-level code: $x') !== false, $output);
    }

    /**
     * @dataProvider mainFailureProvider
     */
    public function testMainFailures($arguments, $exceptionClass, $message)
    {
        try {
            ob_start();
            PgaccInfectionShim::main($arguments);
            self::fail('No exception');
        } catch (Exception $exception) {
            self::assertSame($exceptionClass, get_class($exception));
            self::assertSame($message, $exception->getMessage());
        } finally {
            self::assertSame('', ob_get_clean());
        }
    }

    public static function mainFailureProvider()
    {
        $usage = 'Usage: php infection-shim.php <functions.php> <output directory>';
        $fixture = self::fixture();
        $missing = self::temporaryPath();
        $file = self::write('not a directory');
        $blocked = self::temporaryPath();
        mkdir($blocked . '/functions.php', 0777, true);
        $refused = self::write("<?php\n");
        return array(
            'no arguments' => array(array('infection-shim.php'), 'InvalidArgumentException', $usage),
            'one argument' => array(array('infection-shim.php', $fixture), 'InvalidArgumentException', $usage),
            'too many' => array(array('infection-shim.php', $fixture, $missing, 'x'), 'InvalidArgumentException', $usage),
            'unreadable source' => array(array('infection-shim.php', $missing, $missing), 'RuntimeException', 'Cannot read ' . $missing),
            'refused source' => array(array('infection-shim.php', $refused, $missing), 'RuntimeException', 'Could not wrap ' . $refused),
            'output under a file' => array(array('infection-shim.php', $fixture, $file . '/out'), 'RuntimeException', 'Cannot create ' . $file . '/out'),
            'unwritable output' => array(array('infection-shim.php', $fixture, $blocked), 'RuntimeException', 'Cannot write ' . $blocked . '/functions.php'),
        );
    }

    /**
     * Line of each named function declaration, first occurrence only.
     */
    private static function functionLines($code)
    {
        $lines = array();
        $line = null;
        foreach (token_get_all($code) as $token) {
            if (is_array($token) && $token[0] === T_FUNCTION) {
                $line = $token[2];
            } elseif (is_array($token) && $token[0] === T_STRING && $line !== null) {
                if (!isset($lines[$token[1]])) {
                    $lines[$token[1]] = $line;
                }
                $line = null;
            } elseif ($token === '(') {
                $line = null;
            }
        }
        return $lines;
    }

    private static function fixture()
    {
        return dirname(__DIR__) . '/fixtures/infection-shim/functions.php';
    }

    private static function temporaryPath()
    {
        return sys_get_temp_dir() . '/pgacc-infection-shim-' . getmypid() . '-' . uniqid('', true);
    }

    private static function write($contents)
    {
        $path = self::temporaryPath() . '.php';
        file_put_contents($path, $contents);
        return $path;
    }

    /**
     * Runs PHP without php.ini (no Xdebug or extensions) and returns
     * array(exit status, combined stdout and stderr).
     */
    private static function php(array $arguments)
    {
        $command = escapeshellarg(PHP_BINARY) . ' -n -d display_errors=stderr -d error_reporting=-1';
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }
        $output = shell_exec($command . ' 2>&1; printf "\\n%d" "$?"');
        $status = (int) substr($output, strrpos($output, "\n") + 1);
        return array($status, substr($output, 0, strrpos($output, "\n")));
    }
}
