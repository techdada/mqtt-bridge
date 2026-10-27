'use strict';
const form = document.getElementById('publish');
const button = form.querySelector('button');
const result = document.getElementById('result');
const endpoint = new URL('index.php', window.location.href);
if (window.location.protocol === 'https:') button.disabled = false;
form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (window.location.protocol !== 'https:') return;
    button.disabled = true;
    result.textContent = 'Wird gesendet …';
    const data = new URLSearchParams(new FormData(form));
    data.set('do', 'publish');
    data.set('retain', form.elements.retain.checked ? '1' : '0');
    data.set('allow_spaces', '1');
    try {
        const response = await fetch(endpoint, {
            method: 'POST', body: data, credentials: 'omit',
            cache: 'no-store', redirect: 'error', referrerPolicy: 'no-referrer'
        });
        const body = await response.json();
        result.textContent = response.ok && body.ok === true
            ? 'Der Broker hat die Nachricht bestätigt.'
            : `Fehler (HTTP ${response.status}): ${body.message || 'Unbekannter Fehler'}`;
    } catch (error) {
        result.textContent = 'Keine verlässliche Antwort erhalten. Die Nachricht könnte bereits zugestellt worden sein.';
    } finally {
        form.elements.pass.value = '';
        button.disabled = false;
    }
});
