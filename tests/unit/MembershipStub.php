<?php

/*
 * Controls the membership stub of compose.test.yaml
 * (tests/reference/membership/server.php) from the unit tests: getUsers()
 * then fetches the given response over verified HTTPS, as in production.
 */
final class MembershipStub
{
    private static function control($path, $context = null)
    {
        $answer = file_get_contents('http://mitglieder.piratenpartei.at' . $path, false, $context);
        if ($answer === false) {
            throw new RuntimeException("membership stub control $path failed");
        }
        return $answer;
    }

    // Serves $body as the member list until reset().
    public static function serve($body)
    {
        $context = stream_context_create(array('http' => array(
            'method' => 'POST',
            'header' => "Content-Type: text/plain\r\n",
            'content' => $body,
        )));
        if (self::control('/__stub/body', $context) !== "mode body\n") {
            throw new RuntimeException('membership stub did not accept the body');
        }
    }

    public static function mode($mode)
    {
        if (self::control('/__stub/mode?set=' . $mode) !== "mode $mode\n") {
            throw new RuntimeException("membership stub did not switch to $mode");
        }
    }

    public static function reset()
    {
        self::mode('ok');
    }
}
