<?php
require_once __DIR__.'/lib/config.controller.php';
require_once __DIR__.'/MQTTBridgeController.php';
// Only the optional browser client requires HTTPS. Publish routing is unchanged.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && (!isset($_GET['do']) || $_GET['do'] === 'client') && !isset($_POST['do'])) {
    require __DIR__.'/client.php';
    return;
}
if (!is_file(__DIR__.'/config.php')) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(array('ok' => false, 'message' => 'Bridge configuration missing'))."\n";
    return;
}
require __DIR__.'/config.php';
Config::set('ALL', $config);
new MQTTBridgeController();
