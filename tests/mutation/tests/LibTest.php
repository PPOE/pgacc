<?php
/*
 * Unit tests of tests/mutation/lib.php. Shared by PHPUnit 9.6 (PHP 7.4-8.5)
 * and PHPUnit 5.7 (PHP 5.6): no setUp(), no assertions newer than 5.7.
 * Run with: sh tests/mutation/run.sh <lane> --self-test
 */

class LibTest extends PHPUnit\Framework\TestCase
{
    private static function root()
    {
        return dirname(dirname(dirname(__DIR__)));
    }

    private static function target($overrides = array(), $mutation = array())
    {
        $target = array(
            'description' => 'example',
            'file' => 'example.php',
            'tests' => array(array('type' => 'http', 'script' => 'tests/http-login.php')),
            'mutations' => array(array_merge(array(
                'id' => 'm1',
                'search' => 'a == 1',
                'replace' => 'a != 1',
                'rationale' => 'why',
            ), $mutation)),
        );
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
            $region === null ? array() : array('region' => $region),
            array('search' => $search, 'replace' => $replace)
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
                'Message "' . $exception->getMessage() . '" lacks "' . $expectedMessagePart . '"'
            );
            return;
        }
        $this->fail('Expected a PgaccMutationException containing: ' . $expectedMessagePart);
    }

    public function testDropDatabaseUsesForceFromPostgresql13()
    {
        $this->assertSame(
            array('DROP DATABASE IF EXISTS accounting WITH (FORCE)'),
            pgacc_mutation_drop_database_sql('accounting', '130000')
        );
        $this->assertSame(
            array('DROP DATABASE IF EXISTS accounting WITH (FORCE)'),
            pgacc_mutation_drop_database_sql('accounting', '180001')
        );
    }

    public function testDropDatabaseTerminatesBackendsBeforePostgresql13()
    {
        $expected = array(
            "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = 'accounting' AND pid <> pg_backend_pid()",
            'DROP DATABASE IF EXISTS accounting',
        );
        $this->assertSame($expected, pgacc_mutation_drop_database_sql('accounting', '129999'));
        $this->assertSame($expected, pgacc_mutation_drop_database_sql('accounting', '90426'));
        $this->assertSame($expected, pgacc_mutation_drop_database_sql('accounting', 0));
    }

    public function testValidTargetGetsDefaults()
    {
        $target = pgacc_mutation_validate_target(self::target(array(
            'tests' => array(
                array('type' => 'phpunit', 'filter' => 'testX'),
                array('type' => 'http', 'script' => 'tests/http-smoke.php'),
            ),
        )), 'test');
        $this->assertSame(120, $target['timeout']);
        $this->assertSame('tests/unit/FunctionsTest.php', $target['tests'][0]['file']);
        $mutation = $target['mutations'][0];
        $this->assertSame('example.php', $mutation['file']);
        $this->assertSame(array('a == 1'), $mutation['search']);
        $this->assertSame(array('a != 1'), $mutation['replace']);
        $this->assertNull($mutation['region']);
        $this->assertNull($mutation['equivalent']);
    }

    public function testTargetRegionIsInheritedOnlyForTheTargetFile()
    {
        $region = array('start' => 'function a(', 'end' => 'function b(');
        $same = pgacc_mutation_validate_target(self::target(array('region' => $region)), 't');
        $this->assertSame($region, $same['mutations'][0]['region']);
        $other = pgacc_mutation_validate_target(
            self::target(array('region' => $region), array('file' => 'other.php')),
            't'
        );
        $this->assertNull($other['mutations'][0]['region']);
        $noTargetFile = pgacc_mutation_validate_target(
            self::target(array('region' => $region, 'file' => null), array('file' => 'example.php')),
            't'
        );
        $this->assertNull($noTargetFile['mutations'][0]['region']);
        $own = array('start' => 'x', 'end' => 'y');
        $explicit = pgacc_mutation_validate_target(self::target(array('region' => $region), array('region' => $own)), 't');
        $this->assertSame($own, $explicit['mutations'][0]['region']);
    }

    public function testListedSpellingsWithParallelReplacements()
    {
        $target = pgacc_mutation_validate_target(self::target(array(), array(
            'search' => array('$x = array();', '$x = [];'),
            'replace' => array('$x = array(1);', '$x = [1];'),
        )), 't');
        $this->assertSame(array('$x = array(1);', '$x = [1];'), $target['mutations'][0]['replace']);
        $single = pgacc_mutation_validate_target(self::target(array(), array(
            'search' => array('$x = array();', '$x = [];'),
            'replace' => '',
        )), 't');
        $this->assertSame(array('', ''), $single['mutations'][0]['replace']);
    }

    public function invalidTargets()
    {
        $test = array('type' => 'http', 'script' => 'tests/x.php');
        return array(
            'not an array' => array('x', null, 'must return an array'),
            'no description' => array(array('description' => null), array(), "'description'"),
            'no tests' => array(array('tests' => array()), array(), "'tests' must be a non-empty list"),
            'test without type' => array(array('tests' => array(array('script' => 'a'))), array(), "needs a 'type'"),
            'unknown test type' => array(array('tests' => array(array('type' => 'shell'))), array(), 'unknown test type'),
            'http without script' => array(array('tests' => array(array('type' => 'http'))), array(), "'script'"),
            'absolute script' => array(array('tests' => array(array('type' => 'http', 'script' => '/etc/x.php'))), array(), 'must be relative'),
            'parent script' => array(array('tests' => array(array('type' => 'http', 'script' => 'tests/../../x.php'))), array(), 'must be relative'),
            'phpunit without filter' => array(array('tests' => array(array('type' => 'phpunit'))), array(), "'filter'"),
            'phpunit bad file' => array(array('tests' => array(array('type' => 'phpunit', 'filter' => 'a', 'file' => '../a.php'))), array(), 'must be relative'),
            'zero timeout' => array(array('tests' => array($test), 'timeout' => 0), array(), "'timeout'"),
            'string timeout' => array(array('timeout' => '5'), array(), "'timeout'"),
            'bad target file' => array(array('file' => '/abs.php'), array(), 'must be relative'),
            'bad target region' => array(array('region' => array('start' => 'a')), array(), "'region' needs"),
            'no mutations' => array(array('mutations' => array()), array(), "'mutations' must be a non-empty list"),
            'mutation not array' => array(array('mutations' => array('x')), array(), 'must be an array'),
            'no id' => array(array(), array('id' => ''), "'id'"),
            'bad id' => array(array(), array('id' => 'a b'), 'id may only contain'),
            'reserved id' => array(array(), array('id' => 'baseline'), 'is reserved'),
            'no rationale' => array(array(), array('rationale' => ' '), "'rationale'"),
            'no file anywhere' => array(array('file' => null), array(), "no 'file'"),
            'bad mutation file' => array(array(), array('file' => '../x.php'), 'must be relative'),
            'bad mutation region' => array(array(), array('region' => array('start' => '', 'end' => 'b')), "'region' needs"),
            'no search' => array(array(), array('search' => null), "needs 'search' and 'replace'"),
            'empty search list' => array(array(), array('search' => array()), "'search' must be a string or a list"),
            'search map' => array(array(), array('search' => array('a' => 'x')), "'search' must be a string or a list"),
            'replace list length' => array(array(), array('search' => array('a', 'b'), 'replace' => array('c')), "one entry per 'search'"),
            'empty search' => array(array(), array('search' => ''), 'search non-empty'),
            'non-string replace' => array(array(), array('replace' => 1), 'search non-empty'),
            'identical replace' => array(array(), array('replace' => 'a == 1'), 'identical'),
            'line count changed' => array(array(), array('search' => "a;\nb;", 'replace' => 'b;'), 'keep the line count'),
            'equivalent without reason' => array(array(), array('equivalent' => array('php' => array('5.6'))), "'reason'"),
            'equivalent empty php' => array(array(), array('equivalent' => array('reason' => 'r', 'php' => array())), "equivalent 'php'"),
            'equivalent php string' => array(array(), array('equivalent' => array('reason' => 'r', 'php' => '5.6')), "equivalent 'php'"),
        );
    }

    /**
     * @dataProvider invalidTargets
     */
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
        $mutation = self::mutation(array('$x = array();', '$x = [];'), array('$x = array(1);', '$x = [1];'));
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

    public static function publicMutation($search, $replace, $region = null)
    {
        return self::mutation($search, $replace, $region);
    }

    public function testRegionsRestrictMatchingAndMustBeUnambiguous()
    {
        $source = "function a() { return 1; }\nfunction b() { return 1; }\nfunction c() {}\n";
        $region = array('start' => 'function b(', 'end' => 'function c(');
        $result = pgacc_mutation_apply($source, self::mutation('return 1;', 'return 2;', $region));
        $this->assertSame("function a() { return 1; }\nfunction b() { return 2; }\nfunction c() {}\n", $result['source']);
        $this->assertSame(2, $result['line']);
        $this->assertFails(function () use ($source) {
            pgacc_mutation_apply($source, LibTest::publicMutation('return 1;', 'return 2;'));
        }, 'found 2');
        $this->assertFails(function () use ($source) {
            pgacc_mutation_apply($source, LibTest::publicMutation('function a', 'function x',
                array('start' => 'function b(', 'end' => 'function c(')));
        }, 'not found in the region');
        $this->assertFails(function () use ($source) {
            pgacc_mutation_apply($source, LibTest::publicMutation('return 1;', 'return 2;',
                array('start' => 'function', 'end' => 'function c(')));
        }, 'region start marker must occur exactly once, found 3');
        $this->assertFails(function () use ($source) {
            pgacc_mutation_apply($source, LibTest::publicMutation('return 1;', 'return 2;',
                array('start' => 'function b(', 'end' => 'missing')));
        }, 'region end marker must occur exactly once, found 0');
        $this->assertFails(function () use ($source) {
            pgacc_mutation_apply($source, LibTest::publicMutation('return 1;', 'return 2;',
                array('start' => 'function c(', 'end' => 'function b(')));
        }, 'end marker precedes the start marker');
        $crlf = pgacc_mutation_apply(str_replace("\n", "\r\n", $source),
            self::mutation('return 1;', 'return 2;', array('start' => "1; }\r\nfunction b(", 'end' => 'function c(')));
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
        $pass = array('exit' => 0, 'timed_out' => false);
        $fail = array('exit' => 1, 'timed_out' => false);
        $timeout = array('exit' => null, 'timed_out' => true);
        $this->assertSame('invalid', pgacc_mutation_classify(255, array()));
        $this->assertSame('invalid', pgacc_mutation_classify(1, array($fail)));
        $this->assertSame('survived', pgacc_mutation_classify(0, array($pass, $pass)));
        $this->assertSame('killed', pgacc_mutation_classify(0, array($pass, $fail)));
        $this->assertSame('timeout', pgacc_mutation_classify(0, array($pass, $timeout)));
        $this->assertSame('killed', pgacc_mutation_classify(0, array($fail, $timeout)));
        $this->assertSame('timeout', pgacc_mutation_classify(0, array($timeout, $fail)));
    }

    public function testEquivalentsAreScopedToPhpVersions()
    {
        $any = array('equivalent' => array('reason' => 'same output'));
        $only56 = array('equivalent' => array('reason' => 'hidden notice', 'php' => array('5.6')));
        $none = array('equivalent' => null);
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
        $results = array();
        foreach ($statuses as $i => $status) {
            $results[] = array('id' => "m$i", 'status' => $status, 'file' => 'a.php', 'line' => $i + 1,
                'seconds' => 1.25, 'note' => $status === 'equivalent' ? 'accepted: r' : null);
        }
        return $results;
    }

    public function testSummaryPassesOnlyWithoutUnacceptedSurvivors()
    {
        $passing = pgacc_mutation_summarize(self::results(array('killed', 'timeout', 'equivalent', 'killed')));
        $this->assertSame(0, $passing['exit_code']);
        $this->assertSame(3, $passing['detected']);
        $this->assertSame(100.0, $passing['score']);
        $this->assertSame(1, $passing['counts']['equivalent']);
        foreach (array('survived', 'invalid', 'stale-equivalent') as $failing) {
            $summary = pgacc_mutation_summarize(self::results(array('killed', $failing)));
            $this->assertSame(1, $summary['exit_code'], $failing);
        }
        $partial = pgacc_mutation_summarize(self::results(array('killed', 'survived', 'invalid')));
        $this->assertSame(50.0, $partial['score']);
        $this->assertSame(4, pgacc_mutation_summarize(self::results(array('killed', 'killed', 'killed', 'survived')))['total']);
        $empty = pgacc_mutation_summarize(self::results(array('invalid')));
        $this->assertNull($empty['score']);
        $this->assertFails(function () {
            pgacc_mutation_summarize(array(array('status' => 'unknown')));
        }, 'unknown mutant status');
    }

    public function testFormattedSummary()
    {
        $results = self::results(array('killed', 'equivalent'));
        $text = pgacc_mutation_format_summary('t', '7.4.33', $results, pgacc_mutation_summarize($results));
        $this->assertTrue(strpos($text, 'Mutation target t on PHP 7.4.33: 2 mutant(s)') === 0);
        $this->assertTrue(strpos($text, 'EQUIVALENT') !== false);
        $this->assertTrue(strpos($text, '(accepted: r)') !== false);
        $this->assertTrue(strpos($text, 'a.php:2  1.3s') !== false || strpos($text, 'a.php:2  1.2s') !== false);
        $this->assertTrue(strpos($text, 'score 100%') !== false);
        $this->assertTrue(strpos($text, "Result: PASS\n") !== false);
        $failing = self::results(array('survived', 'invalid'));
        $text = pgacc_mutation_format_summary('t', '5.6.40', $failing, pgacc_mutation_summarize($failing));
        $this->assertTrue(strpos($text, 'SURVIVED') !== false);
        $this->assertTrue(strpos($text, 'score 0%') !== false);
        $this->assertTrue(strpos($text, 'Result: FAIL') !== false);
        $none = self::results(array('invalid'));
        $text = pgacc_mutation_format_summary('t', '5.6.40', $none, pgacc_mutation_summarize($none));
        $this->assertTrue(strpos($text, 'score n/a') !== false);
    }

    public function testCommandQuotesArgumentsAndEnvironment()
    {
        $command = pgacc_mutation_command(array('php', "it's"), array('A_B' => 'x y'));
        $this->assertSame("exec env 'A_B=x y' setsid 'php' 'it'\\''s'", $command);
        $this->assertFails(function () {
            pgacc_mutation_command(array('php'), array('A=B' => 'x'));
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
            array(PHP_BINARY, '-r', 'echo getenv("PGACC_X"), getcwd(); exit(3);'),
            array('PGACC_X' => 'value:'),
            sys_get_temp_dir(),
            $log,
            30
        );
        $this->assertSame(3, $result['exit']);
        $this->assertFalse($result['timed_out']);
        $this->assertSame('value:' . realpath(sys_get_temp_dir()), file_get_contents($log));
        $ok = pgacc_mutation_run(array(PHP_BINARY, '-r', 'exit(0);'), array(), null, $log, 30);
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
        $result = pgacc_mutation_run(array(PHP_BINARY, '-r', $parent), array(), null, $log, 1);
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
        $result = pgacc_mutation_run(array(PHP_BINARY, '-r', $parent), array(), null, $log, 30);
        $this->assertSame(0, $result['exit']);
        sleep(3);
        $this->assertFalse(file_exists($marker), 'orphaned child kept running');
        unlink($log);
    }

    public function testStartAndStopAServer()
    {
        $log = self::log();
        $handle = pgacc_mutation_start(array(PHP_BINARY, '-r', 'sleep(30);'), array(), null, $log);
        $this->assertTrue(proc_get_status($handle['process'])['running']);
        $started = microtime(true);
        pgacc_mutation_stop($handle);
        $this->assertTrue(microtime(true) - $started < 5);
        unlink($log);
    }

    public function testStartFailsLoudlyWhenTheLogCannotBeOpened()
    {
        $this->assertFails(function () {
            pgacc_mutation_start(array(PHP_BINARY, '-v'), array(), null, '/nonexistent-dir/log');
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
        $settings = array();
        foreach ($items[1] as $i => $item) {
            if ($item === '-d') {
                $settings[] = str_replace('/app/', '%APP%/', $items[1][$i + 1]);
            }
        }
        $this->assertSame($settings, pgacc_mutation_server_ini());
    }

    public function testFixturesMatchTheDatabaseSmokeTest()
    {
        $script = file_get_contents(self::root() . '/tests/database-smoke.sh');
        preg_match_all('/--file=(\S+)/', $script, $files);
        $this->assertSame($files[1], pgacc_mutation_fixture_files());
    }
}
