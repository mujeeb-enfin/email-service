<?php

if (!function_exists('sendResponse')) {

    /**
     * Standard API JSON response
     *
     * @param bool $error
     * @param string $message
     * @param int $status_code
     * @param mixed $data
     * @return \CodeIgniter\HTTP\Response
     */
    function sendResponse(bool $error = false, string $message = '', int $status_code = 200, $data = null)
    {
        $response = [
            'error'       => $error,
            'message'     => $message,
            'data'        => $data,
            'status_code' => $status_code,
        ];

        // Get CodeIgniter instance
        $ci = \Config\Services::response();

        return $ci->setStatusCode($status_code)
                  ->setContentType('application/json')
                  ->setBody(json_encode($response));
    }

}
