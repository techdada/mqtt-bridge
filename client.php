<?php
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
// Trust the web server's TLS indicator, never arbitrary forwarding headers.
if (!in_array(strtolower((string)($_SERVER['HTTPS'] ?? '')), array('on', '1'), true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "The test client requires HTTPS. The HTTP publishing bridge remains available.\n";
    return;
}
http_response_code(200);
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="de">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MQTT-Testclient</title>
<link rel="stylesheet" href="client.css">
<body>
<main class="panel">
<header><span class="badge">MQTT BRIDGE</span>
<h1>MQTT-Testclient</h1>
<p>Veröffentlicht eine Nachricht über diesen Server am konfigurierten Broker.</p>
</header>
<form id="publish" autocomplete="off">
  <fieldset><legend>Broker-Zugang</legend><div class="credentials">
  <label>MQTT-Benutzer <input name="user" autocomplete="off" spellcheck="false" autocapitalize="none" required></label>
  <label>MQTT-Passwort <input name="pass" type="password" autocomplete="off" required></label>
  </div></fieldset>
  <fieldset><legend>Nachricht</legend>
  <label>Topic <input name="topic" placeholder="sensors/test" spellcheck="false" autocapitalize="none" required></label>
  <label>Payload <textarea name="value" rows="6" placeholder="Deine Nachricht …" spellcheck="false"></textarea></label>
  <label class="checkbox"><input name="retain" type="checkbox"><span>Retain<small>Nachricht am Broker speichern</small></span></label>
  </fieldset>
  <button type="submit" disabled>Nachricht senden <span aria-hidden="true">↗</span></button>
</form>
<p id="result" role="status" aria-live="polite"></p>
<noscript>Zum sicheren Senden ohne Zugangsdaten in der URL ist JavaScript erforderlich.</noscript>
<footer>HTTPS-Verbindung · QoS 1 · Keine lokale Speicherung</footer>
</main>
<script src="client.js" defer></script>
</body>
</html>
