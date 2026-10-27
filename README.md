# mqtt-bridge
mqtt bridge for PHP

In cases you have devices or applications that cannot directly talk to MQTT message brokers you can enable them by using this simple bridge. All what is required is the capability to do HTTP, better HTTPS requests.

## Installation

Copy to the web root of your favored web server. Copy the config.sample.php to config.php and adapt the values accordingly. Then use it as follows:

* http(s)://{server}:{port}/mqtt-bridge/?do=publish&topic={topic}&value={value}&retain={0|1}[&user={user}&pass={password}]

GET remains supported for limited devices such as the Mobotix camera, including
HTTP within the intended trusted network. There is no additional HTTP login.
Broker credentials and broker topic permissions control MQTT access.

Alternatively POST the same parameters as `application/x-www-form-urlencoded`.
POST parameters take precedence over GET parameters. Percent-encode parameter
values normally (`+` must be `%2B`, `&` must be `%26`). PHP decodes them once.
Payloads and passwords are not stripped, HTML-encoded or evaluated.
There is no shell execution or user-selected include in the endpoint.

`do=publish` and a concrete, nonempty UTF-8 `topic` are required. Topic wildcards,
control characters, invalid UTF-8 and topics over 65535 bytes are rejected.
Spaces become underscores unless `allow_spaces` is present (legacy behavior).
`value` defaults to empty; `valuets` generates a UTC timestamp if the value is
empty. The string `0` remains a value. `retain` accepts `0`, `1`, `false`, `true`
or empty (default false). Array parameters are rejected, not silently coerced.

Responses are small JSON objects, for example `{"ok":true,"message":"Published"}`:

- HTTP 200: the broker acknowledged the QoS 1 publish.
- HTTP 400: invalid request; nothing is published.
- HTTP 405: unsupported method (only GET/POST).
- HTTP 502: connection or delivery failed.
- HTTP 500: configuration missing.

Responses never echo credentials or payloads.
Acknowledgement confirms broker receipt, not execution by downstream actors.
If acknowledgement times out, delivery is uncertain; retrying can duplicate an
event. Event consumers should deduplicate when that matters.

## PHP 8 compatibility

The MQTT client is copied from mqtt-sysmon/lib/phpMQTT.php (no new dependencies).
Legacy GET calls, including `valuets` for a server-generated UTC timestamp,
remain supported. No additional HTTP authentication or HTTPS requirement is
introduced; HTTP can be used by limited devices inside the intended trusted network.
Broker credentials are forwarded unchanged; topic permissions remain the broker's responsibility.
Values are decoded once by PHP and forwarded unchanged, including `0`.

MQTT transport is independent of incoming HTTP: `mqtt_tls=tlsv1.2` enables
verified TLS to the broker; `mqtt_tls=tcp` explicitly selects plaintext.
An empty `ca` uses the system trust store with TLS, rather than selecting plaintext.
Existing installations using an empty CA to select plaintext must set `mqtt_tls=tcp`.
Publish uses QoS 1, and the existing retain parameter is preserved.

Run `php tests/requests.php` for HTTP-routing regression checks without network
access and `php tests/mqtt.php` for local simulated-broker protocol checks.

## Browser test client

Open the directory URL or `?do=client` over HTTPS for the small publish form.
Over HTTP the form returns 403; `?do=publish` continues to work over HTTP.
The web server must set PHP's `HTTPS` variable to `on` or `1` after TLS termination.
Forwarded headers alone are deliberately not trusted.

The form posts to the same bridge, without credentials in the URL, browser storage
or cookies. The password field is cleared after each attempt. No broker secrets
are embedded in the page. JavaScript is required; without it submission is blocked
by the page's Content Security Policy. This is a publish test client, not a subscriber.
Run `php tests/client.php` to verify the HTTPS gate and independent HTTP routing.
