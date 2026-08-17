<?php
/**
 * Live typeahead over the WordPress posts table, streamed over a native
 * ePHPm WebSocket. Each keystroke sends {"action":"search","q":...} to
 * websocket.php, which queries wp_posts in the per-site Turso database and
 * streams the matches back. No REST round trips, no polling.
 */
$published = 0;
try {
    $rows = ephpm_db_query(
        "SELECT COUNT(*) AS c FROM wp_posts WHERE post_status = 'publish' AND post_type = 'post'"
    );
    $published = (int) ($rows[0]['c'] ?? 0);
} catch (\Throwable $e) {
    $published = 0;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ePHPm - Live post search over WebSockets</title>
<style>
  :root { color-scheme: light dark; }
  body { font: 16px/1.5 system-ui, sans-serif; max-width: 720px; margin: 3rem auto; padding: 0 1rem; }
  h1 { font-size: 1.4rem; margin-bottom: .25rem; }
  p.sub { color: #888; margin-top: 0; }
  input { width: 100%; font-size: 1.2rem; padding: .6rem .8rem; box-sizing: border-box;
          border: 1px solid #8888; border-radius: .5rem; background: transparent; color: inherit; }
  #status { font-size: .85rem; color: #888; margin: .5rem 0; }
  ul { list-style: none; padding: 0; }
  li { padding: .6rem .8rem; border: 1px solid #8882; border-radius: .5rem; margin: .4rem 0; }
  li a { text-decoration: none; color: inherit; font-weight: 600; }
  .dot { display: inline-block; width: .6rem; height: .6rem; border-radius: 50%; margin-right: .4rem; vertical-align: middle; }
  .up { background: #2ecc71; } .down { background: #e74c3c; }
  code { background: #8882; padding: .1rem .3rem; border-radius: .25rem; }
</style>
</head>
<body>
  <h1>Live post search</h1>
  <p class="sub">Every keystroke streams a query over a native WebSocket to
     <code>websocket.php</code>, which searches <code>wp_posts</code> in the
     per-site Turso database. <?= $published ?> published post<?= $published === 1 ? '' : 's' ?> indexed.</p>

  <input id="q" type="text" placeholder="Type to search post titles..." autocomplete="off" autofocus>
  <p id="status"><span class="dot down"></span>connecting...</p>
  <ul id="results"></ul>

  <script>
    const wsUrl = (location.protocol === 'https:' ? 'wss' : 'ws') + '://' + location.host + '/';
    const statusEl = document.getElementById('status');
    const resultsEl = document.getElementById('results');
    const input = document.getElementById('q');
    let ws, pending = null;

    function setStatus(up, text) {
      statusEl.innerHTML = '<span class="dot ' + (up ? 'up' : 'down') + '"></span>' + text;
    }

    function connect() {
      ws = new WebSocket(wsUrl);
      ws.onopen = () => { setStatus(true, 'connected - ' + wsUrl); if (pending !== null) send(pending); };
      ws.onclose = () => { setStatus(false, 'disconnected - retrying...'); setTimeout(connect, 1000); };
      ws.onmessage = (ev) => {
        let msg; try { msg = JSON.parse(ev.data); } catch (e) { return; }
        if (msg.type !== 'search') return;
        render(msg);
      };
    }

    function render(msg) {
      resultsEl.innerHTML = '';
      if (!msg.results || msg.results.length === 0) {
        resultsEl.innerHTML = '<li style="color:#888">' +
          (msg.q ? 'No posts match "' + escapeHtml(msg.q) + '"' : 'Start typing...') + '</li>';
        return;
      }
      for (const r of msg.results) {
        const li = document.createElement('li');
        const a = document.createElement('a');
        a.href = '?p=' + r.id;
        a.textContent = r.title;
        li.appendChild(a);
        resultsEl.appendChild(li);
      }
    }

    function send(q) {
      pending = q;
      if (ws && ws.readyState === WebSocket.OPEN) {
        ws.send(JSON.stringify({ action: 'search', q })); pending = null;
      }
    }

    function escapeHtml(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    let t;
    input.addEventListener('input', () => {
      clearTimeout(t);
      t = setTimeout(() => send(input.value), 80);
    });

    connect();
  </script>
</body>
</html>
