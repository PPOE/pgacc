<?php
/**
 * Request class.
 *
 * @package api-framework
 * @author Martin Bean <martin@martinbean.co.uk>
 */
class Request
{
    /**
     * The URL as a string.
     * @var string
     */
    public $url;

    /**
     * URL segments as an array.
     * @var array
     */
    public $segments = array();

    /**
     * The request method (GET, POST etc).
     * @var string
     */
    public $method;

    /**
     * Parameters array, depending on HTTP method used.
     * @var array
     */
    public $parameters = array();

    /**
     * Constructs a request object from an URL.
     *
     * @param string $url Optional; will default to current URL if none is passed
     */
    public function __construct($url = null)
    {
        if (is_null($url)) {
            $path_info = '';
            $path_info = (isset($_SERVER['PATH_INFO'])) ? $_SERVER['PATH_INFO'] : $path_info;
            $path_info = (isset($_SERVER['ORIG_PATH_INFO'])) ? $_SERVER['ORIG_PATH_INFO'] : $path_info;
            $url = htmlspecialchars($path_info, ENT_QUOTES, "UTF-8");
        }

        $this->url = $url;
        $this->segments = explode('/', trim($this->url, '/'));
        // for now you can only get data.
        $this->method = strtoupper($_SERVER['REQUEST_METHOD']);
        //$this->method = 'GET';
        /*if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] != '') {
            $this->method = strtoupper($_SERVER['REQUEST_METHOD']);
        } else {
            $this->method = 'GET';
        }*/
        $this->parameters = isset($_GET) && count($_GET) != 0 ? $_GET : $_POST;

        /* $_SERVER['REQUEST_METHOD'] doesn't have anything to do with $_GET und $_POST */
        switch ($this->method) {
            case 'GET':
                $this->parameters = $_GET;
                break;
            case 'POST':
                $this->parameters = $_POST;
                break;
            case 'PUT':
                parse_str(file_get_contents('php://input'), $this->parameters);
                break;
        }
    }
}