<?php

/*
 * Application code ends the request with die('message') on database and
 * input errors. Under PHPUnit that would end the whole run with exit status
 * 0, so a test that calls such code wraps the call in enter()/leave(): if the
 * process ends in between, the shutdown function makes it exit with status 1.
 */
final class AppExitGuard
{
    private static $active = null;
    private static $registered = false;

    public static function enter($label)
    {
        if (!self::$registered) {
            register_shutdown_function(array(__CLASS__, 'shutdown'));
            self::$registered = true;
        }
        self::$active = $label;
    }

    public static function leave()
    {
        self::$active = null;
    }

    public static function shutdown()
    {
        if (self::$active !== null) {
            fwrite(STDERR, "\nApplication code ended the PHPUnit process (die/exit) in " . self::$active . "\n");
            exit(1);
        }
    }
}
