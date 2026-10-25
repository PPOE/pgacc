<?php

/*
 * Test stream wrapper over a real file ("pgacc-short://<path>") whose reads
 * return at most ShortReadStream::$limit bytes. With $stall set, reads return
 * nothing without ever reaching the end of the file. Used by
 * tests/unit/ReceiptStorageTest.php for short and failed reads.
 */
final class ShortReadStream
{
    public static $limit = 7;
    public static $stall = false;

    public $context;
    private $handle;

    public static function register()
    {
        if (!in_array('pgacc-short', stream_get_wrappers(), true)) {
            stream_wrapper_register('pgacc-short', __CLASS__);
        }
        self::$limit = 7;
        self::$stall = false;
    }

    public function stream_open($path, $mode, $options, &$openedPath)
    {
        $this->handle = fopen(substr($path, strlen('pgacc-short://')), $mode);
        return $this->handle !== false;
    }

    public function stream_read($count)
    {
        return self::$stall ? '' : (string) fread($this->handle, min($count, self::$limit));
    }

    public function stream_eof()
    {
        return !self::$stall && feof($this->handle);
    }

    public function stream_seek($offset, $whence)
    {
        return fseek($this->handle, $offset, $whence) === 0;
    }

    public function stream_tell()
    {
        return ftell($this->handle);
    }

    public function stream_stat()
    {
        return fstat($this->handle);
    }

    public function stream_close()
    {
        fclose($this->handle);
    }
}
