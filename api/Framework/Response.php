<?php
/**
 * Response class factory.
 *
 * @package api-framework
 * @author Martin Bean <martin@martinbean.co.uk>
 */
class Response
{
    /**
     * Constructor.
     *
     * @param string $data
     * @param string $format
     */
    public static function create($data, $format)
    {
        header('Access-Control-Allow-Origin: *');
        switch ($format) {
            case 'application/json':
            default:
                $obj = new ResponseJson($data);
                break;
        }
        return $obj;
    }
}