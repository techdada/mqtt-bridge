<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
error_reporting(E_ALL);
set_error_handler(function ($level, $message, $file, $line) {
    throw new ErrorException($message, 0, $level, $file, $line);
});
require __DIR__.'/../MQTTBridgeController.php';
class TestBridge extends MQTTBridgeController {
    public $sent;
    public $status;
    public $response;
    protected function publish($topic, $value, $retain, $user, $pass) {
        $this->sent = array($topic, $value, $retain, $user, $pass);
        if ($topic === 'fail') throw new RuntimeException('secret internal failure');
    }
    protected function respond($status, $message) {
        $this->status = $status;
        $this->response = $message;
    }
}
function checkRequest($ok, $message) { if (!$ok) throw new RuntimeException($message); }
function request($params, $post = array(), $method = 'GET') {
    parse_str(http_build_query($params), $_GET);
    $_POST = $post;
    $_SERVER['REQUEST_METHOD'] = $method;
    return new TestBridge();
}
$base = array('do'=>'publish', 'topic'=>'sensors/cameras/gartentor/Ring', 'user'=>'bell', 'pass'=>'secret');
$b = request($base + array('valuets'=>''));
checkRequest($b->status === 200 && abs(time()-strtotime($b->sent[1])) < 3 && !$b->sent[2], 'Legacy bell call');
$value = "<script>alert(1)</script> + %20 & '\"\r\n\0";
$b = request(array_merge($base, array('value'=>$value, 'user'=>'bell.user@host', 'pass'=>'<p>+%&', 'retain'=>'true')));
checkRequest($b->sent[1] === $value && $b->sent[3] === 'bell.user@host' && $b->sent[4] === '<p>+%&' && $b->sent[2], 'Data mutated');
checkRequest($b->response === 'Published', 'Response reflects input');
$b = request(array_merge($base, array('value'=>'0', 'valuets'=>'', 'user'=>'0', 'pass'=>'0')));
checkRequest($b->sent[1] === '0' && $b->sent[3] === '0' && $b->sent[4] === '0', 'Zero lost');
$b = request(array(), $base + array('value'=>'post'), 'POST');
checkRequest($b->status === 200 && $b->sent[1] === 'post', 'POST unsupported');
$b = request($base, array('value'=>'override', 'topic'=>'post/topic'), 'POST');
checkRequest($b->sent[0] === 'post/topic', 'POST precedence');
foreach (array('topic'=>array('', 'bad/+', 'bad/#', "bad\0", "bad\n", "\xff", str_repeat('x',65536), array('x')),
    'retain'=>array('yes', '1evil', array('1')), 'user'=>array(array('x'), "bad\0"),
    'value'=>array(array('x')), 'pass'=>array(array('x')), 'do'=>array('subscribe')) as $key=>$values) {
    foreach ($values as $value) {
        $b = request(array_merge($base, array($key=>$value)));
        checkRequest($b->status === 400 && $b->sent === null, 'Invalid '.$key.' reached broker');
    }
}
$b = request(array_merge($base, array('topic'=>'fail')));
checkRequest($b->status === 502 && strpos($b->response,'secret') === false, 'Failure response');
$b = request($base, array(), 'DELETE');
checkRequest($b->status === 405 && $b->sent === null, 'Method validation');
$b = request(array_merge($base, array('topic'=>'room name')));
checkRequest($b->sent[0] === 'room_name', 'Legacy topic spaces');
$b = request(array_merge($base, array('topic'=>'room name','allow_spaces'=>'')));
checkRequest($b->sent[0] === 'room name', 'Explicit topic spaces');
foreach (array('<?php system("id"); ?>', '$(id); cat /etc/passwd', '../../config.php',
    "\r\nSet-Cookie: injected=1", '<img src=x onerror=alert(1)>') as $payload) {
    $b = request($base + array('value'=>$payload));
    checkRequest($b->status === 200 && $b->sent[1] === $payload && $b->response === 'Published', 'Payload was interpreted or reflected');
}
$b = request($base + array('value'=>str_repeat('x',1048576)));
checkRequest($b->status === 413 && $b->sent === null, 'Oversized message reached broker');
class TransportBridge extends MQTTBridgeController {
    public static $broker;
    protected function mqtt() { return self::$broker; }
}
$broker = new class {
    public $connected = true;
    public $published = true;
    public $closed = 0;
    public $args;
    function connect(...$args) { return $this->connected; }
    function publish(...$args) { $this->args = $args; return $this->published; }
    function close() { $this->closed++; return true; }
};
TransportBridge::$broker = $broker;
$_GET = $base + array('value'=>'payload'); $_POST = array();
$_SERVER['REQUEST_METHOD'] = 'GET';
foreach (array(200,502,502) as $index=>$expected) {
    $broker->connected = $index !== 2;
    $broker->published = $index === 0;
    ob_start();
    new TransportBridge();
    $response = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
    checkRequest(http_response_code() === $expected && $response['ok'] === ($expected === 200), 'Actual HTTP result');
    checkRequest($broker->closed === $index+1, 'Connection not closed');
}
checkRequest($broker->args === array($base['topic'], 'payload', 1, false), 'Transport payload or QoS changed');
echo "PASS GET/POST, exact data, validation, HTTP results, QoS1 and transport failures\n";
