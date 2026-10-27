<?php
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
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
<h1>MQTT-Testclient</h1>
<p>Veröffentlicht eine Nachricht über diesen Server am konfigurierten Broker.</p>
<form id="publish" autocomplete="off">
  <p><label>MQTT-Benutzer <input name="user" autocomplete="off" required></label></p>
  <p><label>MQTT-Passwort <input name="pass" type="password" autocomplete="off" required></label></p>
  <p><label>Topic <input name="topic" required></label></p>
  <p><label>Nachricht <textarea name="value" rows="5" cols="50"></textarea></label></p>
  <p><label><input name="retain" type="checkbox"> Retain: Nachricht am Broker speichern</label></p>
  <button type="submit" disabled>Senden</button>
</form>
<p id="result" role="status" aria-live="polite"></p>
<noscript>Zum sicheren Senden ohne Zugangsdaten in der URL ist JavaScript erforderlich.</noscript>
<script src="client.js" defer></script>
</html>
