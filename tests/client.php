<?php
error_reporting(E_ALL);
set_error_handler(function ($level, $message, $file, $line) {
    throw new ErrorException($message, 0, $level, $file, $line);
});
foreach (array('' => 403, 'off' => 403, 'on' => 200, '1' => 200) as $https => $expected) {
    $_SERVER['HTTPS'] = (string)$https;
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
    ob_start();
    include __DIR__.'/../client.php';
    $body = ob_get_clean();
    if (http_response_code() !== $expected || (strpos($body, '<form') !== false) !== ($expected === 200)) {
        throw new RuntimeException('HTTPS gate failed');
    }
}
// index.php must route HTTP publish requests to the bridge, not the HTTPS gate.
$_SERVER['HTTPS'] = 'off';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = array('do' => 'publish'); $_POST = array();
if (!is_file(__DIR__.'/../config.php')) {
    ob_start(); include __DIR__.'/../index.php'; $body = ob_get_clean();
    if (http_response_code() !== 500 || strpos($body, 'configuration missing') === false) {
        throw new RuntimeException('HTTP publish routed to client');
    }
}
echo "PASS HTTPS-only client, ignored forwarded headers and independent HTTP bridge\n";
