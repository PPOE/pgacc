<?php

/*
 * Unit tests of tests/mutation/lib.php.
 * Run with: sh tests/mutation/run.sh <lane> --self-test
 */

class LibTest extends PHPUnit\Framework\TestCase
{
    private static function root()
    {
        return dirname(dirname(dirname(__DIR__)));
    }

    private static function target($overrides = [], $mutation = [])
    {
        $target = [
            'description' => 'example',
            'file' => 'example.php',
            'tests' => [['type' => 'http', 'script' => 'tests/http-login.php']],
            'mutations' => [array_merge([
                'id' => 'm1',
                'search' => 'a == 1',
                'replace' => 'a != 1',
                'rationale' => 'why',
            ], $mutation)],
        ];
        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($target[$key]);
            } else {
                $target[$key] = $value;
            }
        }
        return $target;
    }

    private static function mutation($search, $replace, $region = null)
    {
        $target = pgacc_mutation_validate_target(self::target(
            $region === null ? [] : ['region' => $region],
            ['search' => $search, 'replace' => $replace],
        ), 'test');
        return $target['mutations'][0];
    }

    private function assertFails($callback, $expectedMessagePart)
    {
        try {
            call_user_func($callback);
        } catch (PgaccMutationException $exception) {
            $this->assertTrue(
                strpos($exception->getMessage(), $expectedMessagePart) !== false,
                'Message "' . $exception->getMessage() . '" lacks "' . $expectedMessagePart . '"',
            );
            return;
        }
        $this->fail('Expected a PgaccMutationException containing: ' . $expectedMessagePart);
    }

    public function testDropDatabaseUsesForce()
    {
        $this->assertSame(
            ['DROP DATABASE IF EXISTS accounting WITH (FORCE)'],
            pgacc_mutation_drop_database_sql('accounting'),
        );
    }

    public function testValidTargetGetsDefaults()
    {
        $target = pgacc_mutation_validate_target(self::target([
            'tests' => [
                ['type' => 'phpunit', 'filter' => 'testX'],
                ['type' => 'http', 'script' => 'tests/http-smoke.php'],
            ],
        ]), 'test');
        $this->assertSame(120, $target['timeout']);
        $this->assertSame('tests/unit/FunctionsTest.php', $target['tests'][0]['file']);
        $mutation = $target['mutations'][0];
        $this->assertSame('example.php', $mutation['file']);
        $this->assertSame(['a == 1'], $mutation['search']);
        $this->assertSame(['a != 1'], $mutation['replace']);
        $this->assertNull($mutation['region']);
        $this->assertNull($mutation['equivalent']);
    }

    // Formerly covered by the PHP 7.4-only equivalents of the committed targets.
    public function testEquivalentWithPhpListIsKept()
    {
        $equivalent = ['reason' => 'r', 'php' => ['8.5']];
        $target = pgacc_mutation_validate_target(self::target([], ['equivalent' => $equivalent]), 'test');
        $this->assertSame($equivalent, $target['mutations'][0]['equivalent']);
    }

    public function testTargetRegionIsInheritedOnlyForTheTargetFile()
    {
        $region = ['start' => 'function a(', 'end' => 'function b('];
        $same = pgacc_mutation_validate_target(self::target(['region' => $region]), 't');
        $this->assertSame($region, $same['mutations'][0]['region']);
        $other = pgacc_mutation_validate_target(
            self::target(['region' => $region], ['file' => 'other.php']),
            't',
        );
        $this->assertNull($other['mutations'][0]['region']);
        $noTargetFile = pgacc_mutation_validate_target(
            self::target(['region' => $region, 'file' => null], ['file' => 'example.php']),
            't',
        );
        $this->assertNull($noTargetFile['mutations'][0]['region']);
        $own = ['start' => 'x', 'end' => 'y'];
        $explicit = pgacc_mutation_validate_target(self::target(['region' => $region], ['region' => $own]), 't');
        $this->assertSame($own, $explicit['mutations'][0]['region']);
    }

    public function testListedSpellingsWithParallelReplacements()
    {
        $target = pgacc_mutation_validate_target(self::target([], [
            'search' => ['$x = array();', '$x = [];'],
            'replace' => ['$x = array(1);', '$x = [1];'],
        ]), 't');
        $this->assertSame(['$x = array(1);', '$x = [1];'], $target['mutations'][0]['replace']);
        $single = pgacc_mutation_validate_target(self::target([], [
            'search' => ['$x = array();', '$x = [];'],
            'replace' => '',
        ]), 't');
        $this->assertSame(['', ''], $single['mutations'][0]['replace']);
    }

    public static function invalidTargets()
    {
        $test = ['type' => 'http', 'script' => 'tests/x.php'];
        return [
            'not an array' => ['x', null, 'must return an array'],
            'no description' => [['description' => null], [], "'description'"],
            'no tests' => [['tests' => []], [], "'tests' must be a non-empty list"],
            'test without type' => [['tests' => [['script' => 'a']]], [], "needs a 'type'"],
            'unknown test type' => [['tests' => [['type' => 'shell']]], [], 'unknown test type'],
            'http without script' => [['tests' => [['type' => 'http']]], [], "'script'"],
            'absolute script' => [['tests' => [['type' => 'http', 'script' => '/etc/x.php']]], [], 'must be relative'],
            'parent script' => [['tests' => [['type' => 'http', 'script' => 'tests/../../x.php']]], [], 'must be relative'],
            'phpunit without filter' => [['tests' => [['type' => 'phpunit']]], [], "'filter'"],
            'phpunit bad file' => [['tests' => [['type' => 'phpunit', 'filter' => 'a', 'file' => '../a.php']]], [], 'must be relative'],
            'zero timeout' => [['tests' => [$test], 'timeout' => 0], [], "'timeout'"],
            'string timeout' => [['timeout' => '5'], [], "'timeout'"],
            'bad target file' => [['file' => '/abs.php'], [], 'must be relative'],
            'bad target region' => [['region' => ['start' => 'a']], [], "'region' needs"],
            'no mutations' => [['mutations' => []], [], "'mutations' must be a non-empty list"],
            'mutation not array' => [['mutations' => ['x']], [], 'must be an array'],
            'no id' => [[], ['id' => ''], "'id'"],
            'bad id' => [[], ['id' => 'a b'], 'id may only contain'],
            'reserved id' => [[], ['id' => 'baseline'], 'is reserved'],
            'no rationale' => [[], ['rationale' => ' '], "'rationale'"],
            'no file anywhere' => [['file' => null], [], "no 'file'"],
            'bad mutation file' => [[], ['file' => '../x.php'], 'must be relative'],
            'bad mutation region' => [[], ['region' => ['start' => '', 'end' => 'b']], "'region' needs"],
            'no search' => [[], ['search' => null], "needs 'search' and 'replace'"],
            'empty search list' => [[], ['search' => []], "'search' must be a string or a list"],
            'search map' => [[], ['search' => ['a' => 'x']], "'search' must be a string or a list"],
            'replace list length' => [[], ['search' => ['a', 'b'], 'replace' => ['c']], "one entry per 'search'"],
            'empty search' => [[], ['search' => ''], 'search non-empty'],
            'non-string replace' => [[], ['replace' => 1], 'search non-empty'],
            'identical replace' => [[], ['replace' => 'a == 1'], 'identical'],
            'line count changed' => [[], ['search' => "a;\nb;", 'replace' => 'b;'], 'keep the line count'],
            'equivalent without reason' => [[], ['equivalent' => ['php' => ['5.6']]], "'reason'"],
            'equivalent empty php' => [[], ['equivalent' => ['reason' => 'r', 'php' => []]], "equivalent 'php'"],
            'equivalent php string' => [[], ['equivalent' => ['reason' => 'r', 'php' => '5.6']], "equivalent 'php'"],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('invalidTargets')]
    public function testInvalidTargetsFailLoudly($overrides, $mutation, $message)
    {
        $target = is_array($overrides) ? self::target($overrides, $mutation) : $overrides;
        if (is_array($mutation) && array_key_exists('search', $mutation) && $mutation['search'] === null) {
            unset($target['mutations'][0]['search']);
        }
        $this->assertFails(function () use ($target) {
            pgacc_mutation_validate_target($target, 'test');
        }, $message);
    }

    public function testDuplicateMutationIdsFail()
    {
        $target = self::target();
        $target['mutations'][] = $target['mutations'][0];
        $this->assertFails(function () use ($target) {
            pgacc_mutation_validate_target($target, 'test');
        }, 'duplicate mutation id m1');
    }

    public function testApplyReplacesTheOnlyMatchAndReportsTheLine()
    {
        $result = pgacc_mutation_apply("<?php\n\nif (a == 1) {}\n", self::mutation('a == 1', 'a != 1'));
        $this->assertSame("<?php\n\nif (a != 1) {}\n", $result['source']);
        $this->assertSame(3, $result['line']);
        $this->assertSame(0, $result['spelling']);
    }

    public function testApplyRejectsMissingAndRepeatedMatches()
    {
        $mutation = self::mutation('a == 1', 'a != 1');
        $this->assertFails(function () use ($mutation) {
            pgacc_mutation_apply('b == 1', $mutation);
        }, 'search string not found in the file');
        $this->assertFails(function () use ($mutation) {
            pgacc_mutation_apply('a == 1; a == 1;', $mutation);
        }, 'must match exactly once in the file, found 2');
    }

    public function testApplyAcceptsOnlyListedEquivalentSpellings()
    {
        $mutation = self::mutation(['$x = array();', '$x = [];'], ['$x = array(1);', '$x = [1];']);
        $long = pgacc_mutation_apply('$x = array();', $mutation);
        $this->assertSame('$x = array(1);', $long['source']);
        $this->assertSame(0, $long['spelling']);
        $short = pgacc_mutation_apply('$x = [];', $mutation);
        $this->assertSame('$x = [1];', $short['source']);
        $this->assertSame(1, $short['spelling']);
        $this->assertFails(function () use ($mutation) {
            pgacc_mutation_apply('$x = array(); $x = [];', $mutation);
        }, 'more than one listed spelling matches');
        $unlisted = self::mutation('$x = array();', '$x = array(1);');
        $this->assertFails(function () use ($unlisted) {
            pgacc_mutation_apply('$x = [];', $unlisted);
        }, 'not found');
    }

    public function testApplyKeepsCrlfLineEndings()
    {
        $source = "<?php\r\nif (a == 1)\r\n  b();\r\n";
        $result = pgacc_mutation_apply($source, self::mutation("if (a == 1)\n  b();", "if (a != 1)\n  b();"));
        $this->assertSame("<?php\r\nif (a != 1)\r\n  b();\r\n", $result['source']);
        $this->assertSame(2, $result['line']);
        $this->assertFails(function () {
            pgacc_mutation_apply("a == 1\r\nb\n", LibTest::publicMutation('a == 1', 'a != 1'));
        }, 'mixes CRLF and LF');
    }

    public function testApplyReportsTheSearchRegionInLines()
    {
        $source = "<?php\nfunction a() {\n  if (a == 1)\n    b();\n}\nfunction c() {}\n";
        $plain = pgacc_mutation_apply($source, self::mutation("if (a == 1)\n    b();", "if (a != 1)\n    b();"));
        $this->assertSame([3, 4], $plain['span']);
        $region = ['start' => 'function a(', 'end' => 'function c('];
        $inRegion = pgacc_mutation_apply($source, self::mutation('a == 1', 'a != 1', $region));
        $this->assertSame(3, $inRegion['line']);
        $this->assertSame([2, 6], $inRegion['span']);
        $multiLineEnd = ['start' => 'function a(', 'end' => "}\nfunction c("];
        $this->assertSame([2, 6], pgacc_mutation_apply(
            $source,
            self::mutation('a == 1', 'a != 1', $multiLineEnd),
        )['span']);
        $crlf = pgacc_mutation_apply(str_replace("\n", "\r\n", $source), self::mutation('a == 1', 'a != 1', $region));
        $this->assertSame([2, 6], $crlf['span']);
    }

    public function testParseDiffReturnsChangedWorkingTreeLines()
    {
        $diff = implode("\n", [
            'diff --git a/edit.php b/edit.php',
            'index 1111111..2222222 100644',
            '--- a/edit.php',
            '+++ b/edit.php',
            '@@ -10 +10 @@ function x()',
            '-old',
            '+new',
            '@@ -20,0 +21,3 @@',
            '+a',
            '+++ looks like a header but is an added line',
            '+c',
            '@@ -30,2 +33,0 @@',
            '-gone',
            '-gone too',
            'diff --git a/removed.php b/removed.php',
            'deleted file mode 100644',
            '--- a/removed.php',
            '+++ /dev/null',
            '@@ -1,2 +0,0 @@',
            '-x',
            '-y',
            'diff --git a/old.php b/renamed.php',
            'similarity index 90%',
            'rename from old.php',
            'rename to renamed.php',
            '--- a/old.php',
            '+++ b/renamed.php',
            '@@ -0,0 +1 @@',
            '+first',
            'diff --git a/top.php b/top.php',
            '--- a/top.php',
            '+++ b/top.php',
            '@@ -1 +0,0 @@',
            '-first line removed',
            'diff --git a/new.txt b/new.txt',
            'new file mode 100644',
            '--- /dev/null',
            '+++ b/new.txt',
            '@@ -0,0 +1,2 @@',
            '+one',
            '+two',
            '',
        ]);
        $this->assertSame([
            'edit.php' => [[10, 10], [21, 23], [33, 34]],
            'renamed.php' => [[1, 1]],
            'top.php' => [[1, 1]],
            'new.txt' => [[1, 2]],
        ], pgacc_mutation_parse_diff($diff));
        $this->assertSame([], pgacc_mutation_parse_diff(''));
        $this->assertSame(
            ['a.php' => [[2, 2]]],
            pgacc_mutation_parse_diff("diff --git a/a.php b/a.php\r\n--- a/a.php\r\n+++ b/a.php\r\n@@ -2 +2 @@\r\n-x\r\n+y\r\n"),
        );
    }

    public function testLinesChangedChecksOverlapWithEveryRange()
    {
        $ranges = ['edit.php' => [[10, 12], [30, 30]]];
        $this->assertTrue(pgacc_mutation_lines_changed($ranges, 'edit.php', 12, 20));
        $this->assertTrue(pgacc_mutation_lines_changed($ranges, 'edit.php', 1, 10));
        $this->assertTrue(pgacc_mutation_lines_changed($ranges, 'edit.php', 11, 11));
        $this->assertTrue(pgacc_mutation_lines_changed($ranges, 'edit.php', 30, 30));
        $this->assertTrue(pgacc_mutation_lines_changed($ranges, 'edit.php', 25, 40));
        $this->assertFalse(pgacc_mutation_lines_changed($ranges, 'edit.php', 13, 29));
        $this->assertFalse(pgacc_mutation_lines_changed($ranges, 'edit.php', 1, 9));
        $this->assertFalse(pgacc_mutation_lines_changed($ranges, 'edit.php', 31, 99));
        $this->assertFalse(pgacc_mutation_lines_changed($ranges, 'new.php', 10, 12));
    }

    public function testSelectChangedKeepsMutationsWhoseSearchRegionChanged()
    {
        $mutations = [
            ['id' => 'in-edit', 'file' => 'edit.php'],
            ['id' => 'in-edit-later', 'file' => 'edit.php'],
            ['id' => 'in-new', 'file' => 'new.php'],
        ];
        $applied = [
            'in-edit' => ['span' => [5, 9]],
            'in-edit-later' => ['span' => [40, 40]],
            'in-new' => ['span' => [5, 9]],
        ];
        $ranges = ['edit.php' => [[9, 12]], 'tests/write-workflows.php' => [[1, 500]]];
        $selected = pgacc_mutation_select_changed($mutations, $applied, $ranges, 'tests/mutation/targets/t.php');
        $this->assertSame([$mutations[0]], $selected);
        $this->assertSame([], pgacc_mutation_select_changed(
            $mutations,
            $applied,
            ['tests/write-workflows.php' => [[1, 500]]],
            'tests/mutation/targets/t.php',
        ));
        $this->assertSame($mutations, pgacc_mutation_select_changed(
            $mutations,
            $applied,
            ['tests/mutation/targets/t.php' => [[3, 3]]],
            'tests/mutation/targets/t.php',
        ));
        $this->assertSame([], pgacc_mutation_select_changed($mutations, $applied, [], 'tests/mutation/targets/t.php'));
    }

    public static function publicMutation($search, $replace, $region = null)
    {
        return self::mutation($search, $replace, $region);
    }

    public function testRegionsRestrictMatchingAndMustBeUnambiguous()
    {
        $source = "function a() { return 1; }\nfunction b() { return 1; }\nfunction c() {}\n";
        $region = ['start' => 'function b(', 'end' => 'function c('];
        $result = pgacc_mutation_apply($source, self::mutation('return 1;', 'return 2;', $region));
        $this->assertSame("function a() { return 1; }\nfunction b() { return 2; }\nfunction c() {}\n", $result['source']);
        $this->assertSame(2, $result['line']);
        $this->assertFails(function () use ($source) {
            pgacc_mutation_apply($source, LibTest::publicMutation('return 1;', 'return 2;'));
        }, 'found 2');
        $this->assertFails(function () use ($source) {
            pgacc_mutation_apply($source, LibTest::publicMutation(
                'function a',
                'function x',
                ['start' => 'function b(', 'end' => 'function c('],
            ));
        }, 'not found in the region');
        $this->assertFails(function () use ($source) {
            pgacc_mutation_apply($source, LibTest::publicMutation(
                'return 1;',
                'return 2;',
                ['start' => 'function', 'end' => 'function c('],
            ));
        }, 'region start marker must occur exactly once, found 3');
        $this->assertFails(function () use ($source) {
            pgacc_mutation_apply($source, LibTest::publicMutation(
                'return 1;',
                'return 2;',
                ['start' => 'function b(', 'end' => 'missing'],
            ));
        }, 'region end marker must occur exactly once, found 0');
        $this->assertFails(function () use ($source) {
            pgacc_mutation_apply($source, LibTest::publicMutation(
                'return 1;',
                'return 2;',
                ['start' => 'function c(', 'end' => 'function b('],
            ));
        }, 'end marker precedes the start marker');
        $crlf = pgacc_mutation_apply(
            str_replace("\n", "\r\n", $source),
            self::mutation('return 1;', 'return 2;', ['start' => "1; }\r\nfunction b(", 'end' => 'function c(']),
        );
        $this->assertSame(2, $crlf['line']);
    }

    public function testCountRejectsEmptyNeedles()
    {
        $this->assertSame(2, pgacc_mutation_count('aXa', 'a'));
        $this->assertFails(function () {
            pgacc_mutation_count('abc', '');
        }, 'non-empty');
    }

    public function testClassifyOutcomes()
    {
        $pass = ['exit' => 0, 'timed_out' => false];
        $fail = ['exit' => 1, 'timed_out' => false];
        $timeout = ['exit' => null, 'timed_out' => true];
        $this->assertSame('invalid', pgacc_mutation_classify(255, []));
        $this->assertSame('invalid', pgacc_mutation_classify(1, [$fail]));
        $this->assertSame('survived', pgacc_mutation_classify(0, [$pass, $pass]));
        $this->assertSame('killed', pgacc_mutation_classify(0, [$pass, $fail]));
        $this->assertSame('timeout', pgacc_mutation_classify(0, [$pass, $timeout]));
        $this->assertSame('killed', pgacc_mutation_classify(0, [$fail, $timeout]));
        $this->assertSame('timeout', pgacc_mutation_classify(0, [$timeout, $fail]));
    }

    public function testEquivalentsAreScopedToPhpVersions()
    {
        $any = ['equivalent' => ['reason' => 'same output']];
        $only56 = ['equivalent' => ['reason' => 'hidden notice', 'php' => ['5.6']]];
        $none = ['equivalent' => null];
        $this->assertSame('same output', pgacc_mutation_equivalent_reason($any, '8.5'));
        $this->assertSame('hidden notice', pgacc_mutation_equivalent_reason($only56, '5.6'));
        $this->assertNull(pgacc_mutation_equivalent_reason($only56, '7.4'));
        $this->assertNull(pgacc_mutation_equivalent_reason($none, '5.6'));

        $this->assertSame('equivalent', pgacc_mutation_final_status('survived', $only56, '5.6'));
        $this->assertSame('survived', pgacc_mutation_final_status('survived', $only56, '7.4'));
        $this->assertSame('killed', pgacc_mutation_final_status('killed', $only56, '7.4'));
        $this->assertSame('stale-equivalent', pgacc_mutation_final_status('killed', $only56, '5.6'));
        $this->assertSame('stale-equivalent', pgacc_mutation_final_status('timeout', $any, '7.4'));
        $this->assertSame('invalid', pgacc_mutation_final_status('invalid', $any, '7.4'));
        $this->assertSame('killed', pgacc_mutation_final_status('killed', $none, '7.4'));
    }

    private static function results($statuses)
    {
        $results = [];
        foreach ($statuses as $i => $status) {
            $results[] = ['id' => "m$i", 'status' => $status, 'file' => 'a.php', 'line' => $i + 1,
                'seconds' => 1.25, 'note' => $status === 'equivalent' ? 'accepted: r' : null];
        }
        return $results;
    }

    public function testSummaryPassesOnlyWithoutUnacceptedSurvivors()
    {
        $passing = pgacc_mutation_summarize(self::results(['killed', 'timeout', 'equivalent', 'killed']));
        $this->assertSame(0, $passing['exit_code']);
        $this->assertSame(3, $passing['detected']);
        $this->assertSame(100.0, $passing['score']);
        $this->assertSame(1, $passing['counts']['equivalent']);
        foreach (['survived', 'invalid', 'stale-equivalent'] as $failing) {
            $summary = pgacc_mutation_summarize(self::results(['killed', $failing]));
            $this->assertSame(1, $summary['exit_code'], $failing);
        }
        $partial = pgacc_mutation_summarize(self::results(['killed', 'survived', 'invalid']));
        $this->assertSame(50.0, $partial['score']);
        $this->assertSame(4, pgacc_mutation_summarize(self::results(['killed', 'killed', 'killed', 'survived']))['total']);
        $empty = pgacc_mutation_summarize(self::results(['invalid']));
        $this->assertNull($empty['score']);
        $this->assertFails(function () {
            pgacc_mutation_summarize([['status' => 'unknown']]);
        }, 'unknown mutant status');
    }

    public function testFormattedSummary()
    {
        $results = self::results(['killed', 'equivalent']);
        $text = pgacc_mutation_format_summary('t', '7.4.33', $results, pgacc_mutation_summarize($results));
        $this->assertTrue(strpos($text, 'Mutation target t on PHP 7.4.33: 2 mutant(s)') === 0);
        $this->assertTrue(strpos($text, 'EQUIVALENT') !== false);
        $this->assertTrue(strpos($text, '(accepted: r)') !== false);
        $this->assertTrue(strpos($text, 'a.php:2  1.3s') !== false || strpos($text, 'a.php:2  1.2s') !== false);
        $this->assertTrue(strpos($text, 'score 100%') !== false);
        $this->assertTrue(strpos($text, "Result: PASS\n") !== false);
        $failing = self::results(['survived', 'invalid']);
        $text = pgacc_mutation_format_summary('t', '5.6.40', $failing, pgacc_mutation_summarize($failing));
        $this->assertTrue(strpos($text, 'SURVIVED') !== false);
        $this->assertTrue(strpos($text, 'score 0%') !== false);
        $this->assertTrue(strpos($text, 'Result: FAIL') !== false);
        $none = self::results(['invalid']);
        $text = pgacc_mutation_format_summary('t', '5.6.40', $none, pgacc_mutation_summarize($none));
        $this->assertTrue(strpos($text, 'score n/a') !== false);
    }

    public function testCommandQuotesArgumentsAndEnvironment()
    {
        $command = pgacc_mutation_command(['php', "it's"], ['A_B' => 'x y']);
        $this->assertSame("exec env 'A_B=x y' setsid 'php' 'it'\\''s'", $command);
        $this->assertFails(function () {
            pgacc_mutation_command(['php'], ['A=B' => 'x']);
        }, 'invalid environment variable name');
    }

    private static function log()
    {
        return tempnam(sys_get_temp_dir(), 'pgacc-mutation-test');
    }

    public function testRunReportsExitStatusOutputAndEnvironment()
    {
        $log = self::log();
        $result = pgacc_mutation_run(
            [PHP_BINARY, '-r', 'echo getenv("PGACC_X"), getcwd(); exit(3);'],
            ['PGACC_X' => 'value:'],
            sys_get_temp_dir(),
            $log,
            30,
        );
        $this->assertSame(3, $result['exit']);
        $this->assertFalse($result['timed_out']);
        $this->assertSame('value:' . realpath(sys_get_temp_dir()), file_get_contents($log));
        $ok = pgacc_mutation_run([PHP_BINARY, '-r', 'exit(0);'], [], null, $log, 30);
        $this->assertSame(0, $ok['exit']);
        unlink($log);
    }

    public function testRunStopsTheWholeProcessGroupOnTimeout()
    {
        $log = self::log();
        $marker = $log . '.child';
        // The child would write the marker after 3s unless the group is killed.
        $child = 'sleep(3); file_put_contents(' . var_export($marker, true) . ', "alive");';
        $parent = 'exec(' . var_export(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($child) . ' >/dev/null 2>&1 &', true) . '); sleep(30);';
        $started = microtime(true);
        $result = pgacc_mutation_run([PHP_BINARY, '-r', $parent], [], null, $log, 1);
        $this->assertTrue($result['timed_out']);
        $this->assertNull($result['exit']);
        $this->assertTrue(microtime(true) - $started < 10);
        $this->assertTrue(strpos(file_get_contents($log), 'timed out after 1s') !== false);
        sleep(4);
        $this->assertFalse(file_exists($marker), 'child process survived the timeout');
        unlink($log);
    }

    public function testRunStopsChildrenLeftBehindByACompletedCommand()
    {
        $log = self::log();
        $marker = $log . '.orphan';
        $child = 'sleep(2); file_put_contents(' . var_export($marker, true) . ', "alive");';
        $parent = 'exec(' . var_export(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($child) . ' >/dev/null 2>&1 &', true) . ');';
        $result = pgacc_mutation_run([PHP_BINARY, '-r', $parent], [], null, $log, 30);
        $this->assertSame(0, $result['exit']);
        sleep(3);
        $this->assertFalse(file_exists($marker), 'orphaned child kept running');
        unlink($log);
    }

    public function testStartAndStopAServer()
    {
        $log = self::log();
        $handle = pgacc_mutation_start([PHP_BINARY, '-r', 'sleep(30);'], [], null, $log);
        $this->assertTrue(proc_get_status($handle['process'])['running']);
        $started = microtime(true);
        pgacc_mutation_stop($handle);
        $this->assertTrue(microtime(true) - $started < 5);
        unlink($log);
    }

    public function testStartFailsLoudlyWhenTheLogCannotBeOpened()
    {
        $this->assertFails(function () {
            pgacc_mutation_start([PHP_BINARY, '-v'], [], null, '/nonexistent-dir/log');
        }, 'could not start');
    }

    public function testLoadTarget()
    {
        $path = self::log() . '.php';
        file_put_contents($path, '<?php return ' . var_export(self::target(), true) . ';');
        $target = pgacc_mutation_load_target($path);
        $this->assertSame('m1', $target['mutations'][0]['id']);
        unlink($path);
        $this->assertFails(function () use ($path) {
            pgacc_mutation_load_target($path);
        }, 'target file not found');
    }

    // Every committed target must stay valid against the current sources, so
    // application changes that break a definition fail here, not in a run.
    public function testCommittedTargetsApplyToTheCurrentSources()
    {
        $targets = glob(self::root() . '/tests/mutation/targets/*.php');
        $this->assertTrue(count($targets) >= 2);
        foreach ($targets as $path) {
            $target = pgacc_mutation_load_target($path);
            foreach ($target['mutations'] as $mutation) {
                $source = file_get_contents(self::root() . '/' . $mutation['file']);
                $result = pgacc_mutation_apply($source, $mutation);
                $this->assertNotSame($source, $result['source'], $mutation['id']);
                $this->assertSame(substr_count($source, "\n"), substr_count($result['source'], "\n"), $mutation['id']);
            }
            foreach ($target['tests'] as $test) {
                $file = $test['type'] === 'http' ? $test['script'] : $test['file'];
                $this->assertFileExists(self::root() . '/' . $file);
            }
        }
    }

    public function testServerSettingsMatchTheApplicationService()
    {
        $compose = file_get_contents(self::root() . '/compose.test.yaml');
        $this->assertSame(1, preg_match('/\n  application:\n(.*?)\n  application-test:/s', $compose, $service));
        $this->assertSame(1, preg_match('/\n    command:\n((?:      - .*\n)+)/', $service[1], $command));
        preg_match_all('/      - (.*)\n/', $command[1], $items);
        $settings = [];
        foreach ($items[1] as $i => $item) {
            if ($item === '-d') {
                $settings[] = str_replace('/app/', '%APP%/', $items[1][$i + 1]);
            }
        }
        $this->assertSame($settings, pgacc_mutation_server_ini());
    }

    public function testFixturesMatchTheDatabaseSmokeTest()
    {
        // The same files in the same order.
        $script = file_get_contents(self::root() . '/tests/database-smoke.sh');
        preg_match_all('/--file=(\S+)/', $script, $files);
        $this->assertSame([
            'tests/fixtures/pgcrypto.sql',
            'tests/fixtures/schema.sql',
            'tests/fixtures/data.sql',
            'tests/database-characterization.sql',
        ], $files[1]);
        $this->assertSame(pgacc_mutation_fixture_files(), $files[1]);
        foreach ($files[1] as $file) {
            $this->assertFileExists(self::root() . '/' . $file);
        }
    }
}
