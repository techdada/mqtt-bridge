<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Local protocol regression tests; no broker, packages or credentials required.
require __DIR__ . '/../lib/phpMQTT.php';

function check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
function exact($socket, $size) {
    $data = '';
    while (strlen($data) < $size) {
        $chunk = fread($socket, $size - strlen($data));
        check($chunk !== false && $chunk !== '', 'Unexpected EOF/timeout');
        $data .= $chunk;
    }
    return $data;
}
function packet($socket) {
    $header = ord(exact($socket, 1));
    $length = 0;
    $multiplier = 1;
    do {
        $digit = ord(exact($socket, 1));
        $length += ($digit & 127) * $multiplier;
        $multiplier *= 128;
    } while ($digit & 128);
    return array($header, $length ? exact($socket, $length) : '');
}

if (isset($argv[1]) && $argv[1] === 'client') {
    $case = $argv[3];
    $mqtt = new techdada\phpMQTT('127.0.0.1', (int)$argv[2], 'regression', null, false);
    $mqtt->timeout = 0.25;
    $connected = $mqtt->connect(true, null, str_repeat('u', 150), '0');
    if (in_array($case, array('short', 'refused', 'malformed'), true)) {
        check(!$connected && !is_resource($mqtt->getsocket()), 'Invalid CONNACK accepted');
        exit(0);
    }
    check($connected, $mqtt->lastError);
    if ($case === 'subscribe') {
        $received = array();
        check($mqtt->subscribe(array('a.b/#' => array('qos' => 1, 'function' => function ($t, $m) use (&$received) {
            $received[] = array($t, $m);
        }))), $mqtt->lastError);
        check($mqtt->proc(false), $mqtt->lastError);
        check($received === array(array('a.b', 'hello')), 'Topic or QoS 1 payload incorrect');
    } else {
        $ok = $mqtt->publish('test/value', 'payload', 1, true);
        if (in_array($case, array('noack', 'wrongid'), true)) {
            check(!$ok && !is_resource($mqtt->getsocket()), 'Missing/incorrect PUBACK accepted');
            exit(0);
        }
        check($ok, $mqtt->lastError);
        check($mqtt->publishwhenchanged('test/value', 'payload', 1, true), 'Unchanged publish failed');
        try { $mqtt->publish('test/value', 'bad', 2); check(false, 'QoS 2 accepted'); }
        catch (InvalidArgumentException $e) {}
        check($mqtt->publish('test/zero', '0', 0), 'QoS 0 failed');
    }
    check($mqtt->close(), 'Close failed');
    check($mqtt->close(), 'Repeated close failed');
    exit(0);
}

foreach (array('success', 'noack', 'wrongid', 'short', 'refused', 'malformed', 'subscribe') as $case) {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    check((bool)$server, $errstr);
    $address = stream_socket_get_name($server, false);
    $port = substr(strrchr($address, ':'), 1);
    $process = proc_open(array(PHP_BINARY, __FILE__, 'client', $port, $case),
        array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $socket = stream_socket_accept($server, 5);
    check((bool)$socket, 'Client did not connect');
    stream_set_timeout($socket, 2);
    list($header, $body) = packet($socket);
    check($header === 0x10 && substr($body, 0, 7) === "\x00\x04MQTT\x04", 'Not MQTT 3.1.1');
    check(strlen($body) > 127 && substr($body, -3) === "\x00\x010", 'Long CONNECT or password 0 broken');
    if ($case === 'short') {
        fwrite($socket, "\x20\x02\x00");
        fclose($socket);
        $socket = null;
    } elseif ($case === 'refused') {
        fwrite($socket, "\x20\x02\x00\x05");
    } elseif ($case === 'malformed') {
        fwrite($socket, "\x20\x80\x80\x80\x80");
    } else {
        foreach (str_split("\x20\x02\x00\x00") as $byte) { fwrite($socket, $byte); usleep(5000); }
        list($header, $body) = packet($socket);
        if ($case === 'subscribe') {
            check($header === 0x82, 'Invalid SUBSCRIBE flags');
            fwrite($socket, "\x90\x03" . substr($body, 0, 2) . "\x01");
            fwrite($socket, "\x32\x0c\x00\x03a.b\x00\x2ahello");
            check(packet($socket) === array(0x40, "\x00\x2a"), 'Missing inbound PUBACK');
        } else {
            check($header === 0x33, 'QoS/retain flags incorrect');
            $length = unpack('nlength', substr($body, 0, 2))['length'];
            $id = substr($body, 2 + $length, 2);
            if ($case === 'noack') {
                usleep(400000);
            } else {
                $ack = "\x40\x02" . ($case === 'wrongid' ? "\x7f\xff" : $id);
                foreach (str_split($ack) as $byte) { fwrite($socket, $byte); usleep(5000); }
                if ($case === 'success') {
                    list($header, $body) = packet($socket);
                    check($header === 0x30 && substr($body, -1) === '0', 'QoS 0 or change cache broken');
                }
            }
        }
        if ($case === 'success' || $case === 'subscribe') check(packet($socket) === array(0xe0, ''), 'Missing DISCONNECT');
    }
    if (is_resource($socket)) fclose($socket);
    fclose($server);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($process) === 0 && $errors === '', $case . ': ' . $out . $errors);
    echo 'PASS ' . $case . PHP_EOL;
}
