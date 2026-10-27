<?php

namespace techdada;

/*
 * phpMQTT - a small MQTT 3.1.1 client, using PHP streams only.
 * Supports publishing/subscribing at QoS 0 and 1. QoS 2 is rejected.
 * QoS 1 publish() waits for PUBACK. A timeout means delivery is unknown;
 * the caller may retry, but duplicates are possible. No durable outbox.
 *
 * Copyright (c) 2010 Blue Rhinos Consulting | Andrew Milsted
 * andrew@bluerhinos.co.uk | http://www.bluerhinos.co.uk
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */
class phpMQTT
{
    private $socket;
    private $msgid = 1;
    private $keepalive = 60;
    private $lastWrite = 0;
    private $pingSent;
    private $username;
    private $password;
    private $tls_prot;
    private $topichistory = array();
    private $waiting = false;
    private $clean = true;
    public $topics = array();
    public $debug = false;
    public $address;
    public $port;
    public $clientid;
    public $will;
    public $cafile;
    public $timeout = 5;
    public $connectTimeout = 10;
    public $maxPacketSize = 1048576;
    public $lastError = '';

    // TLS: true/'tls' permits TLS 1.2/1.3, 'tlsv1.2' pins 1.2.
    // false/null/''/'tcp'/'plain' disables TLS, independently of cafile.
    function __construct($address, $port, $clientid, $cafile = null, $tls_prot = 'tls', $debug = false)
    {
        $this->broker($address, $port, $clientid, $cafile, $tls_prot, $debug);
    }

    function broker($address, $port, $clientid, $cafile = null, $tls_prot = 'tls', $debug = false)
    {
        $this->address = $address;
        $this->port = $port;
        $this->clientid = $clientid;
        $this->cafile = $cafile;
        $this->tls_prot = $tls_prot;
        $this->debug = $debug;
    }

    function getsocket() { return $this->socket; }

    private function drop()
    {
        if (is_resource($this->socket)) fclose($this->socket);
        $this->socket = null;
        $this->pingSent = null;
    }

    private function fail($message)
    {
        $this->lastError = $message;
        if ($this->debug) error_log('MQTT: ' . $message);
        $this->drop();
        return false;
    }

    private function validString($value, $empty = true)
    {
        return is_string($value) && ($empty || $value !== '') && strlen($value) <= 65535
            && preg_match('//u', $value) === 1
            && strpos($value, "\x00") === false;
    }

    private function validTopic($topic)
    {
        return $this->validString($topic, false) && strpbrk($topic, '+#') === false;
    }

    private function qos($qos)
    {
        if (!in_array($qos, array(0, 1, '0', '1'), true)) {
            throw new \InvalidArgumentException('Only MQTT QoS 0 and 1 are supported.');
        }
        return (int)$qos;
    }

    private function nextId()
    {
        $id = $this->msgid;
        $this->msgid = $id === 65535 ? 1 : $id + 1;
        return $id;
    }

    function connect_auto($clean = true, $will = null, $username = null, $password = null)
    {
        while (!$this->connect($clean, $will, $username, $password)) sleep(10);
        return true;
    }

    function connect($clean = true, $will = null, $username = null, $password = null)
    {
        $this->drop();
        $this->lastError = '';
        $this->clean = (bool)$clean;
        // Omitted arguments preserve credentials and will during reconnect.
        if (func_num_args() >= 2) $this->will = $will;
        if (func_num_args() >= 3) $this->username = $username;
        if (func_num_args() >= 4) $this->password = $password;
        if (!$this->validString($this->clientid, false)) {
            throw new \InvalidArgumentException('Invalid MQTT client ID.');
        }
        $flags = $clean ? 2 : 0;
        $i = 0;
        $payload = $this->strwritestring($this->clientid, $i);
        if ($this->will !== null) {
            if (!isset($this->will['topic'], $this->will['content'], $this->will['qos'], $this->will['retain'])
                || !$this->validTopic($this->will['topic']) || !is_string($this->will['content'])
                || strlen($this->will['content']) > 65535) {
                throw new \InvalidArgumentException('Invalid MQTT will.');
            }
            $flags |= 4 | ($this->qos($this->will['qos']) << 3) | ($this->will['retain'] ? 32 : 0);
            $payload .= $this->strwritestring($this->will['topic'], $i)
                . $this->strwritestring($this->will['content'], $i);
        }
        if ($this->username !== null && $this->username !== false) {
            if (!$this->validString($this->username)) throw new \InvalidArgumentException('Invalid MQTT username.');
            $flags |= 128;
            $payload .= $this->strwritestring($this->username, $i);
            if ($this->password !== null && $this->password !== false) {
                if (!is_string($this->password) || strlen($this->password) > 65535) {
                    throw new \InvalidArgumentException('Invalid MQTT password.');
                }
                $flags |= 64;
                $payload .= $this->strwritestring($this->password, $i);
            }
        } elseif ($this->password !== null && $this->password !== false) {
            throw new \InvalidArgumentException('MQTT password requires a username.');
        }
        $mode = $this->tls_prot === true ? 'tls' : strtolower((string)$this->tls_prot);
        $ssl = array('verify_peer' => true, 'verify_peer_name' => true);
        if (in_array($mode, array('', '0', 'false', 'tcp', 'plain'), true)) {
            $transport = 'tcp';
        } else {
            $transport = 'tls';
            if ($mode === 'tls' || $mode === '1' || $mode === 'true') {
                $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) $method |= constant('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT');
            } elseif ($mode === 'tlsv1.2') {
                $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            } elseif ($mode === 'tlsv1.3' && defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $method = constant('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT');
            } else {
                throw new \InvalidArgumentException('Unsupported TLS mode: ' . $mode);
            }
            $ssl['crypto_method'] = $method;
            if ($this->cafile) $ssl['cafile'] = $this->cafile;
        }
        $this->socket = @stream_socket_client($transport . '://' . $this->address . ':' . $this->port,
            $errno, $errstr, $this->connectTimeout, STREAM_CLIENT_CONNECT,
            stream_context_create(array('ssl' => $ssl)));
        if (!$this->socket) return $this->fail('Connection failed: ' . $errno . ' ' . $errstr);
        stream_set_blocking($this->socket, false);
        $body = "\x00\x04MQTT\x04" . chr($flags) . pack('n', $this->keepalive) . $payload;
        if (!$this->send(0x10, $body)) return false;
        $packet = $this->packet(microtime(true) + $this->timeout);
        if ($packet === false) return false;
        if ($packet[0] !== 0x20 || strlen($packet[1]) !== 2 || ord($packet[1][0]) > 1
            || ($clean && ord($packet[1][0]) !== 0) || ord($packet[1][1]) !== 0) {
            return $this->fail('Invalid or refused CONNACK: ' . bin2hex($packet[1]));
        }
        foreach ($this->topichistory as $topic => $value) {
            if ($value['retain'] && !$this->publish($topic, $value['msg'], $value['qos'], true)) return false;
        }
        return true;
    }

    // One deadline covers a complete operation, including fragmented packets.
    private function readExact($count, $deadline)
    {
        $data = '';
        while (strlen($data) < $count) {
            if (!is_resource($this->socket)) return false;
            $chunk = @fread($this->socket, $count - strlen($data));
            if ($chunk === false || ($chunk === '' && feof($this->socket))) return $this->fail('Connection closed while reading.');
            $data .= $chunk;
            if (strlen($data) === $count) break;
            if (microtime(true) >= $deadline) return $this->fail('Read timeout.');
            if ($chunk === '') usleep(1000);
        }
        return $data;
    }

    function read($int = 8192, $nb = false)
    {
        if ($int < 0) throw new \InvalidArgumentException('Negative read length.');
        if (!is_resource($this->socket)) return false;
        if ($int === 0) return '';
        if (!$nb) return $this->readExact($int, microtime(true) + $this->timeout);
        $data = @fread($this->socket, $int);
        if ($data === false || ($data === '' && feof($this->socket))) return $this->fail('Connection closed.');
        return $data;
    }

    private function send($header, $body)
    {
        if (!is_resource($this->socket)) return $this->fail('Not connected.');
        if (strlen($body) > $this->maxPacketSize) return $this->fail('Packet exceeds configured size limit.');
        $data = chr($header) . $this->setmsglength(strlen($body)) . $body;
        $offset = 0;
        $deadline = microtime(true) + $this->timeout;
        while ($offset < strlen($data)) {
            $written = @fwrite($this->socket, substr($data, $offset));
            if ($written === false || feof($this->socket)) return $this->fail('Write failed.');
            $offset += $written;
            if ($offset === strlen($data)) break;
            if (microtime(true) >= $deadline) return $this->fail('Write timeout.');
            if ($written === 0) usleep(1000);
        }
        $this->lastWrite = microtime(true);
        return true;
    }

    private function packet($deadline, $first = null)
    {
        if ($first === null) $first = $this->readExact(1, $deadline);
        if ($first === false) return false;
        $length = 0;
        $multiplier = 1;
        for ($n = 0; $n < 4; $n++) {
            $byte = $this->readExact(1, $deadline);
            if ($byte === false) return false;
            $digit = ord($byte);
            $length += ($digit & 127) * $multiplier;
            if ($length > $this->maxPacketSize) return $this->fail('Incoming packet too large.');
            if (!($digit & 128)) {
                if ($n > 0 && ($digit & 127) === 0) return $this->fail('Non-canonical packet length.');
                $body = $this->readExact($length, $deadline);
                return $body === false ? false : array(ord($first), $body);
            }
            $multiplier *= 128;
        }
        return $this->fail('Malformed packet length.');
    }

    private function waitAck($header, $id)
    {
        if ($this->waiting) throw new \LogicException('Nested MQTT acknowledgement wait.');
        $this->waiting = true;
        $deadline = microtime(true) + $this->timeout;
        try {
            do {
                $packet = $this->packet($deadline);
                if ($packet === false) return false;
                if ($packet[0] === $header) {
                    $body = $packet[1];
                    $length = $header === 0x40 ? 2 : 3;
                    if (strlen($body) !== $length || unpack('nid', substr($body, 0, 2))['id'] !== $id) {
                        return $this->fail('Invalid acknowledgement or packet identifier.');
                    }
                    return $body;
                }
                if (!$this->handle($packet)) return false;
            } while (microtime(true) < $deadline);
            return $this->fail('Acknowledgement timeout.');
        } finally {
            $this->waiting = false;
        }
    }

    function publish($topic, $content, $qos = 0, $retain = 0)
    {
        $qos = $this->qos($qos);
        if ($qos && $this->waiting) throw new \LogicException('QoS 1 publish inside an acknowledgement callback is not supported.');
        if (!$this->validTopic($topic) || (!is_scalar($content) && $content !== null)) {
            throw new \InvalidArgumentException('Invalid publish topic or payload.');
        }
        $content = (string)$content;
        $i = 0;
        $body = $this->strwritestring($topic, $i);
        if ($qos) {
            $id = $this->nextId();
            $body .= pack('n', $id);
        }
        if (!$this->send(0x30 | ($qos << 1) | ($retain ? 1 : 0), $body . $content)) return false;
        if ($qos && $this->waitAck(0x40, $id) === false) return false;
        // Cache only successful sends, so a failed send can be retried.
        $this->topichistory[$topic] = array('msg' => $content, 'qos' => $qos, 'retain' => (bool)$retain);
        return true;
    }

    function publishwhenchanged($topic, $msg, $qos = 0, $retain = 0)
    {
        $qos = $this->qos($qos);
        $value = array('msg' => (string)$msg, 'qos' => $qos, 'retain' => (bool)$retain);
        if (isset($this->topichistory[$topic]) && $this->topichistory[$topic] === $value) return true;
        return $this->publish($topic, $msg, $qos, $retain);
    }

    function subscribe($topics, $qos = 0)
    {
        if ($this->waiting) throw new \LogicException('Subscribe inside an acknowledgement callback is not supported.');
        foreach ($topics as $filter => $topic) {
            if (!$this->validString($filter, false) || preg_match('/(?:[^\/]#|#.+|[^\/]\+|\+[^\/])/', $filter)
                || !isset($topic['qos'], $topic['function']) || !is_callable($topic['function'])) {
                throw new \InvalidArgumentException('Invalid subscription.');
            }
            $requested = $this->qos($topic['qos']);
            $id = $this->nextId();
            $i = 0;
            if (!$this->send(0x82, pack('n', $id) . $this->strwritestring($filter, $i) . chr($requested))) return false;
            // Brokers may send matching PUBLISH packets before SUBACK.
            $this->topics[$filter] = $topic;
            $ack = $this->waitAck(0x90, $id);
            if ($ack === false) return false;
            if (ord($ack[2]) > $requested) return $this->fail('Subscription refused or invalid granted QoS.');
        }
        return true;
    }

    function message($msg)
    {
        if (strlen($msg) < 2) return $this->fail('Truncated publish topic.');
        $length = unpack('nlength', substr($msg, 0, 2))['length'];
        $topic = substr($msg, 2, $length);
        if (strlen($msg) < 2 + $length || !$this->validTopic($topic)) return $this->fail('Invalid publish topic.');
        $payload = substr($msg, 2 + $length);
        foreach ($this->topics as $filter => $subscription) {
            if ($topic[0] === '$' && $filter[0] !== '$') continue;
            $parts = explode('/', $filter);
            $levels = explode('/', $topic);
            $match = true;
            foreach ($parts as $n => $part) {
                if ($part === '#') break;
                if (!isset($levels[$n]) || ($part !== '+' && $part !== $levels[$n])) { $match = false; break; }
            }
            if ($match && (end($parts) === '#' || count($parts) === count($levels))) {
                call_user_func($subscription['function'], $topic, $payload);
            }
        }
        return true;
    }

    private function handle($packet)
    {
        list($header, $body) = $packet;
        if ($header === 0xd0 && $body === '' && $this->pingSent !== null) {
            $this->pingSent = null;
            return true;
        }
        if (($header >> 4) !== 3) return $this->fail('Unexpected MQTT packet: ' . dechex($header));
        $qos = ($header >> 1) & 3;
        if ($qos > 1 || (!$qos && ($header & 8)) || strlen($body) < 2) return $this->fail('Invalid/unsupported PUBLISH flags.');
        $length = unpack('nlength', substr($body, 0, 2))['length'];
        if ($qos) {
            if (strlen($body) < 2 + $length + 2) return $this->fail('Missing publish packet identifier.');
            $id = substr($body, 2 + $length, 2);
            if ($id === "\x00\x00") return $this->fail('Zero packet identifier.');
            $body = substr($body, 0, 2 + $length) . substr($body, 4 + $length);
        }
        if (!$this->message($body)) return false;
        return !$qos || $this->send(0x40, $id);
    }

    function ping()
    {
        if (!$this->send(0xc0, '')) return false;
        if ($this->pingSent === null) $this->pingSent = microtime(true);
        return true;
    }

    function proc($loop = true)
    {
        if (!is_resource($this->socket) || feof($this->socket)) {
            $topics = $this->topics;
            if (!$this->connect($this->clean) || ($topics && !$this->subscribe($topics))) return false;
        }
        $first = $this->read(1, true);
        if ($first === false) return false;
        if ($first !== '') {
            $packet = $this->packet(microtime(true) + $this->timeout, $first);
            if ($packet === false || !$this->handle($packet)) return false;
        } elseif ($loop) usleep(100000);
        $now = microtime(true);
        if ($this->pingSent !== null && $now - $this->pingSent >= $this->timeout) return $this->fail('PINGRESP timeout.');
        if ($this->pingSent === null && $now - $this->lastWrite >= $this->keepalive) return $this->ping();
        return true;
    }

    function disconnect() { return $this->send(0xe0, ''); }

    function close()
    {
        $ok = !is_resource($this->socket) || $this->disconnect();
        $this->drop();
        return $ok;
    }

    function getmsglength(&$msg, &$i)
    {
        $length = 0;
        $multiplier = 1;
        for ($n = 0; $n < 4 && isset($msg[$i]); $n++) {
            $digit = ord($msg[$i++]);
            $length += ($digit & 127) * $multiplier;
            if (!($digit & 128)) return $length;
            $multiplier *= 128;
        }
        throw new \InvalidArgumentException('Malformed MQTT length.');
    }

    function setmsglength($len)
    {
        if ($len < 0 || $len > 268435455) throw new \InvalidArgumentException('Invalid MQTT length.');
        $result = '';
        do {
            $digit = $len & 127;
            $len >>= 7;
            $result .= chr($digit | ($len ? 128 : 0));
        } while ($len);
        return $result;
    }

    function strwritestring($str, &$i)
    {
        $len = strlen($str);
        if ($len > 65535) throw new \InvalidArgumentException('MQTT string too long.');
        $i += $len + 2;
        return pack('n', $len) . $str;
    }

    function printstr($string)
    {
        for ($j = 0; $j < strlen($string); $j++) {
            $num = ord($string[$j]);
            printf("%4d: %08b : 0x%02x : %s\n", $j, $num, $num, $num > 31 ? $string[$j] : ' ');
        }
    }
}
