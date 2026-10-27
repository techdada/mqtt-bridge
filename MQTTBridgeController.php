<?php
require_once __DIR__.'/lib/phpMQTT.php';

class MQTTBridgeController
{
    // POST overrides GET. PHP already URL-decodes both sources.
    private function parameter($name, $default = '')
    {
        $value = $_POST[$name] ?? $_GET[$name] ?? $default;
        if (!is_string($value)) throw new InvalidArgumentException('Invalid parameter: '.$name);
        return $value;
    }

    public function __construct()
    {
        try {
            if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', array('GET', 'POST'), true)) {
                header('Allow: GET, POST');
                $this->respond(405, 'Method not allowed');
                return;
            }
            if ($this->parameter('do') !== 'publish') throw new InvalidArgumentException('Expected do=publish');
            $topic = $this->parameter('topic');
            if ($topic === '' || strlen($topic) > 65535 || preg_match('//u', $topic) !== 1
                || preg_match('/[\x00-\x1f\x7f+#]/', $topic)) {
                throw new InvalidArgumentException('Invalid publish topic');
            }
            // Legacy allow_spaces and valuets are presence-based switches.
            if (!isset($_GET['allow_spaces']) && !isset($_POST['allow_spaces'])) $topic = str_replace(' ', '_', $topic);
            else $this->parameter('allow_spaces');
            $user = $this->parameter('user');
            $pass = $this->parameter('pass');
            if (strlen($user) > 65535 || preg_match('//u', $user) !== 1 || strpos($user, "\0") !== false
                || strlen($pass) > 65535) throw new InvalidArgumentException('Invalid broker credentials');
            $value = $this->parameter('value');
            if (isset($_GET['valuets']) || isset($_POST['valuets'])) {
                $this->parameter('valuets');
                if ($value === '') $value = gmdate('Y-m-d H:i:s e');
            }
            $retain = strtolower($this->parameter('retain', '0'));
            if (!in_array($retain, array('', '0', '1', 'true', 'false'), true)) throw new InvalidArgumentException('Invalid retain');
            $this->publish($topic, $value, in_array($retain, array('1', 'true'), true), $user, $pass);
            $this->respond(200, 'Published');
        } catch (InvalidArgumentException $error) {
            $this->respond(400, $error->getMessage());
        } catch (Throwable $error) {
            $this->respond(502, 'MQTT delivery failed');
        }
    }

    protected function mqtt()
    {
        $ca = Config::get('ca');
        if ($ca && !is_readable($ca)) throw new RuntimeException('Invalid CA file');
        return new techdada\phpMQTT(Config::get('mqtt_broker'), Config::get('mqtt_port'),
            'bridge_'.bin2hex(random_bytes(8)), $ca, Config::get('mqtt_tls'));
    }

    protected function publish($topic, $value, $retain, $user, $pass)
    {
        $mqtt = $this->mqtt();
        try {
            if (!$mqtt->connect(true, null, $user === '' ? null : $user, $user === '' && $pass === '' ? null : $pass)) {
                throw new RuntimeException('Connect failed');
            }
            // Success means broker PUBACK, not completion of downstream actions.
            if (!$mqtt->publish($topic, $value, 1, $retain)) throw new RuntimeException('Publish failed');
        } finally {
            $mqtt->close();
        }
    }

    protected function respond($status, $message)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo json_encode(array('ok' => $status === 200, 'message' => $message), JSON_THROW_ON_ERROR)."\n";
    }
}
