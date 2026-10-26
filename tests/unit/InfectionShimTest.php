<?php

require_once dirname(__DIR__) . '/mutation/InfectionShim.php';

// Tests for the Infection class wrapper (tests/mutation/InfectionShim.php).
final class InfectionShimTest extends PHPUnit\Framework\TestCase
{
    public const SHIM = 'PgaccFunctionsMutationShim';

    public function testWrapsMinimalInputExactly()
    {
        self::assertSame(
            "<?php final class PgaccFunctionsMutationShim { \n"
            . "public static function a(\$x = 1)\n{\n  return \$x;\n}\n"
            . "\n}\n"
            . "function a(...\$arguments) { return PgaccFunctionsMutationShim::a(...\$arguments); }\n",
            PgaccInfectionShim::wrap("<?php\nfunction a(\$x = 1)\n{\n  return \$x;\n}\n"),
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
                . "function b() { return \"{\$x}\${y}\"; }\r\n?>\n\n"),
        );
    }

    public function testKeepsWhitespaceBeforeOpeningTagAndAfterInlineOpeningTag()
    {
        self::assertSame(
            "\n<?php  final class PgaccFunctionsMutationShim { public static function a() {}\n}\n"
            . "function a(...\$arguments) { return PgaccFunctionsMutationShim::a(...\$arguments); }\n",
            PgaccInfectionShim::wrap("\n<?php function a() {}"),
        );
    }

    public function testFixtureFunctionsKeepTheirLines()
    {
        $original = file_get_contents(self::fixture());
        $wrapped = PgaccInfectionShim::wrap($original);

        $lines = self::functionLines($original);
        self::assertSame(
            ['shim_fixture_greet' => 8, 'shim_fixture_sum' => 15, 'shim_fixture_nothing' => 24],
            $lines,
        );
        self::assertSame($lines, self::functionLines($wrapped));
        self::assertSame(array_keys($lines), PgaccInfectionShim::functionNames($wrapped));
        $originalLines = explode("\n", $original);
        $wrappedLines = explode("\n", $wrapped);
        self::assertSame('<?php final class ' . self::SHIM . ' { ', $wrappedLines[0]);
        for ($line = 1; $line < 24; $line++) {
            self::assertSame($originalLines[$line], str_replace('public static function', 'function', $wrappedLines[$line]));
        }
        self::assertSame(['?>', ''], array_slice($originalLines, 24));
        self::assertSame('}', $wrappedLines[24]);
        self::assertSame(29, count($wrappedLines));
    }

    public function testWrapsWithTheGivenClass()
    {
        self::assertSame(
            "<?php  final class C { public static function a() {}\n}\n"
            . "function a(...\$arguments) { return C::a(...\$arguments); }\n",
            PgaccInfectionShim::wrap("<?php function a() {}", 'x.php', 'C'),
        );
    }

    public static function classNameProvider()
    {
        return [
            'functions.php' => ['functions.php', self::SHIM],
            'src/format.php' => ['src/format.php', 'PgaccSrcFormatMutationShim'],
            'separators' => ['a-b_c/d.php', 'PgaccABCDMutationShim'],
            'only the extension is dropped' => ['aphp.php', 'PgaccAphpMutationShim'],
            'not at the end' => ['a.php.b', 'PgaccAPhpBMutationShim'],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('classNameProvider')]
    public function testClassName($file, $class)
    {
        self::assertSame($class, PgaccInfectionShim::className($file));
    }

    public static function applicationFileProvider()
    {
        return [
            'functions.php' => ['functions.php', 15],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('applicationFileProvider')]
    public function testApplicationFileKeepsEveryFunctionOnItsLine($file, $minimum)
    {
        $original = file_get_contents(dirname(dirname(__DIR__)) . '/' . $file);
        $wrapped = PgaccInfectionShim::wrap($original, $file, PgaccInfectionShim::className($file));
        $lines = self::functionLines($original);

        self::assertGreaterThan($minimum, count($lines));
        self::assertSame($lines, self::functionLines($wrapped));
        self::assertSame(array_keys($lines), PgaccInfectionShim::functionNames($wrapped));
        // The file has no closing tag: the class is closed on two added
        // lines after the last one, then one delegate per function.
        self::assertSame(
            substr_count($original, "\n") + 2 + count($lines),
            substr_count($wrapped, "\n"),
        );
        list($status, $output) = self::php(['-l', self::write($wrapped)]);
        self::assertSame(0, $status, $output);
    }

    public function testWrappedFixtureBehavesLikeTheOriginal()
    {
        $driver = self::write(
            "<?php\nrequire \$argv[1];\n\$shim_fixture_prefix = '> ';\n"
            . "var_export(class_exists('" . self::SHIM . "', false));\necho \"\\n\";\n"
            . "var_export(array(shim_fixture_greet(), shim_fixture_greet('PHP', '?'),\n"
            . "  shim_fixture_sum(array(1, -2, 3)), shim_fixture_sum(array(), 5), shim_fixture_nothing()));\n",
        );
        $wrapped = self::write(PgaccInfectionShim::wrap(file_get_contents(self::fixture())));

        $results = "> Hello, world!\n> Hello, PHP?\n" . var_export([5, 3,
            [4, [2, -4, 6]], [5, []], null], true);

        list($status, $output) = self::php([$driver, self::fixture()]);
        self::assertSame(0, $status, $output);
        self::assertSame("false\n" . $results, $output);

        list($status, $output) = self::php([$driver, $wrapped]);
        self::assertSame(0, $status, $output);
        self::assertSame("true\n" . $results, $output);
    }

    #[PHPUnit\Framework\Attributes\DataProvider('refusalProvider')]
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
        $location = 'Location-sensitive construct is not supported: ';
        $reference = 'By-reference parameters or returns are not supported';
        return [
            'statement' => ["<?php\n\$x = 1;\nfunction a() {}\n", 'Unsupported top-level code: $x'],
            'call' => ["<?php\nfunction a() {}\na();\n", 'Unsupported top-level code: a'],
            'class' => ["<?php\nclass A {}\n", 'Unsupported top-level code: class'],
            'leading HTML' => ["#!/usr/bin/env php\n<?php\nfunction a() {}\n", 'Top-level inline HTML is not supported'],
            'trailing HTML' => ["<?php\nfunction a() {}\n?>\nhtml", 'Top-level inline HTML is not supported'],
            'second PHP block' => ["<?php\nfunction a() {}\n?>\n<?php\nfunction b() {}\n", 'Code after the closing tag is not supported'],
            'by-reference parameter' => ["<?php\nfunction a(\$b, &\$c) {}\n", $reference],
            'by-reference return' => ["<?php\nfunction &a() {}\n", $reference],
            'static in signature' => ["<?php\nfunction a(): static {}\n", $scope . 'static'],
            'top-level static' => ["<?php\nstatic \$x;\nfunction a() {}\n", 'Unsupported top-level code: static'],
            'static variable' => ["<?php\nfunction a() { if (true) { static \$calls = 0; } }\n", $scope . 'static'],
            '__FUNCTION__' => ["<?php\nfunction a() { return __FUNCTION__; }\n", $scope . '__FUNCTION__'],
            '__METHOD__' => ["<?php\nfunction a() { return __METHOD__; }\n", $scope . '__METHOD__'],
            '__CLASS__' => ["<?php\nfunction a() { return __CLASS__; }\n", $scope . '__CLASS__'],
            'func_get_args' => ["<?php\nfunction a() { return func_get_args(); }\n", $scope . 'func_get_args'],
            'func_get_arg' => ["<?php\nfunction a() { return func_get_arg(0); }\n", $scope . 'func_get_arg'],
            'func_num_args' => ["<?php\nfunction a() { return func_num_args(); }\n", $scope . 'func_num_args'],
            '__DIR__' => ["<?php\nfunction a() { return __DIR__; }\n", $location . '__DIR__'],
            '__FILE__' => ["<?php\nfunction a() { return __FILE__; }\n", $location . '__FILE__'],
            'empty' => ['', 'Could not wrap x.php'],
            'whitespace only' => [" \n", 'Could not wrap x.php'],
            'no functions' => ["<?php\n// none\n", 'Could not wrap x.php'],
            'unclosed body' => ["<?php\nfunction a() {\n", 'Could not wrap x.php'],
            'missing name' => ["<?php\nfunction", 'Could not wrap x.php'],
            'missing body' => ["<?php\nfunction a()", 'Could not wrap x.php'],
        ];
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

    public function testMainWritesWrappedCopiesIntoNewDirectories()
    {
        $output = self::temporaryPath() . '/a/b';
        $library = dirname(self::fixture()) . '/src/fixture.php';
        ob_start();
        $result = PgaccInfectionShim::main(['infection-shim.php', dirname(self::fixture()), $output, 'functions.php', 'src/fixture.php']);
        self::assertSame(
            'Wrapped 3 functions from ' . self::fixture() . " for Infection\n"
            . 'Wrapped 1 functions from ' . $library . " for Infection\n",
            ob_get_clean(),
        );
        self::assertNull($result);
        self::assertSame(
            PgaccInfectionShim::wrap(file_get_contents(self::fixture())),
            file_get_contents($output . '/functions.php'),
        );
        self::assertSame(
            PgaccInfectionShim::wrap(file_get_contents($library), $library, 'PgaccSrcFixtureMutationShim'),
            file_get_contents($output . '/src/fixture.php'),
        );
        self::assertSame(['.', '..', 'functions.php', 'src'], scandir($output));
        self::assertSame(['.', '..', 'fixture.php'], scandir($output . '/src'));
        self::assertSame(0777 & ~umask(), fileperms($output . '/src') & 0777);
        self::assertSame(0777 & ~umask(), fileperms($output) & 0777);
        self::assertSame(0777 & ~umask(), fileperms(dirname($output)) & 0777);
    }

    public function testWrappedCopiesLoadTogether()
    {
        $output = self::temporaryPath();
        ob_start();
        PgaccInfectionShim::main(['infection-shim.php', dirname(self::fixture()), $output, 'functions.php', 'src/fixture.php']);
        ob_end_clean();
        $driver = self::write(
            "<?php\nrequire \$argv[1] . '/functions.php';\nrequire \$argv[1] . '/src/fixture.php';\n"
            . "var_export(array(class_exists('PgaccSrcFixtureMutationShim', false), shim_fixture_library('PHP')));\n",
        );

        list($status, $output) = self::php([$driver, $output]);
        self::assertSame(0, $status, $output);
        self::assertSame("Hello, PHP!\n" . var_export([true, 6], true), $output);
    }

    public function testCommandLineEntryUsesExistingDirectory()
    {
        $output = self::temporaryPath();
        mkdir($output);
        file_put_contents($output . '/functions.php', 'old');
        $argv = ['infection-shim.php', dirname(self::fixture()), $output, 'functions.php'];
        ob_start();
        include dirname(__DIR__) . '/mutation/infection-shim.php';
        self::assertSame('Wrapped 3 functions from ' . self::fixture() . " for Infection\n", ob_get_clean());
        self::assertSame(
            PgaccInfectionShim::wrap(file_get_contents(self::fixture())),
            file_get_contents($output . '/functions.php'),
        );
    }

    public function testCommandLineExitStatus()
    {
        $script = dirname(__DIR__) . '/mutation/infection-shim.php';
        list($status, $output) = self::php([$script, dirname(self::fixture()), self::temporaryPath(), 'functions.php']);
        self::assertSame(0, $status, $output);
        self::assertStringEndsWith(" for Infection\n", $output);

        $refused = self::write("<?php\n\$x = 1;\n");
        list($status, $output) = self::php([$script, dirname($refused), self::temporaryPath(), basename($refused)]);
        self::assertNotSame(0, $status, $output);
        self::assertTrue(strpos($output, 'Unsupported top-level code: $x') !== false, $output);
    }

    #[PHPUnit\Framework\Attributes\DataProvider('mainFailureProvider')]
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
        $usage = 'Usage: php infection-shim.php <application directory> <output directory> <file>...';
        $fixture = dirname(self::fixture());
        $missing = self::temporaryPath();
        $file = self::write('not a directory');
        $blocked = self::temporaryPath();
        mkdir($blocked . '/functions.php', 0777, true);
        $refused = self::write("<?php\n");
        $flat = self::temporaryPath();
        mkdir($flat);
        file_put_contents($flat . '/src', 'not a directory');
        return [
            'no arguments' => [['infection-shim.php'], 'InvalidArgumentException', $usage],
            'one argument' => [['infection-shim.php', $fixture], 'InvalidArgumentException', $usage],
            'no files' => [['infection-shim.php', $fixture, $missing], 'InvalidArgumentException', $usage],
            'unreadable source' => [['infection-shim.php', $missing, $missing, 'functions.php'], 'RuntimeException', 'Cannot read ' . $missing . '/functions.php'],
            'refused source' => [['infection-shim.php', dirname($refused), $missing, basename($refused)], 'RuntimeException', 'Could not wrap ' . $refused],
            'output under a file' => [['infection-shim.php', $fixture, $file . '/out', 'functions.php'], 'RuntimeException', 'Cannot create ' . $file . '/out'],
            'subdirectory under a file' => [['infection-shim.php', $fixture, $flat, 'src/fixture.php'], 'RuntimeException', 'Cannot create ' . $flat . '/src'],
            'unwritable output' => [['infection-shim.php', $fixture, $blocked, 'functions.php'], 'RuntimeException', 'Cannot write ' . $blocked . '/functions.php'],
        ];
    }

    /**
     * Line of each named function declaration, first occurrence only.
     */
    private static function functionLines($code)
    {
        $lines = [];
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
        return [$status, substr($output, 0, strrpos($output, "\n"))];
    }
}
