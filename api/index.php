<?php
/**
 * API controller
 *
 * Inspired by the api-framework by Martin Bean
 * Github: https://github.com/martinbean/api-framework
 * Thank you for putting your code online. :)
 *
 * @package api-framework
 * @author Martin Bean <martin@martinbean.co.uk>
 * @author Peter Grassberger <petertheone@gmail.com>
 */

/**
 * Generic class autoloader.
 *
 * @param string $class_name
 */
function autoload_class($class_name) {
    $directories = array(
        'Controller/',
        'Framework/'
    );
    foreach ($directories as $directory) {
        $filename = $directory . $class_name . '.php';
        if (is_file($filename)) {
            require($filename);
            break;
        }
    }
}

/**
 * Register autoloader functions.
 */
spl_autoload_register('autoload_class');

/**
 * Parse the incoming request.
 */
$request = new Request();

/**
 * Route the request.
 */
if (!empty($request->segments) && !empty($request->segments[0])) {
    $controller_name = ucfirst($request->segments[0]) . 'Controller';
    if (class_exists($controller_name)) {
        $controller = new $controller_name;
        $action_name = strtolower($request->method);
        $response_str = call_user_func_array(array($controller, $action_name), array($request));
    } else {
        header('HTTP/1.1 404 Not Found');
        $response_str = 'Unknown request: ' . $request->segments[0];
    }
} else {
    $response_str = 'Unknown request';
}

/**
 * Send the response to the client.
 */
$response_obj = Response::create($response_str, $_SERVER['HTTP_ACCEPT']);
echo $response_obj->render();