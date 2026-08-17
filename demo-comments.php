<?php
/**
 * Live comments room for a post, over a native ePHPm WebSocket.
 *
 * On connect, websocket.php subscribes this socket to comments:<postid> and
 * replays recent approved comments from wp_comments (Turso). A comment posted
 * through post-comment.php broadcasts to the channel, so every open viewer
 * sees it instantly — the HTTP-to-socket showcase.
 */
$post_id = (int) ($_GET['post'] ?? 1);
if ($post_id <= 0) {
    $post_id = 1;
}

$title = 'post #' . $post_id;
try {
    $rows = ephpm_db_query(
        "SELECT post_title FROM wp_posts WHERE ID = ? AND post_status = 'publish' AND post_type = 'post' LIMIT 1",
        [$post_id]
    );
    if (!empty($rows[0]['post_title'])) {
        $title = (string) $rows[0]['post_title'];
    }
} catch (\Throwable $e) {
    // keep the fallback title
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ePHPm - Live comments: <?= htmlspecialchars($title, ENT_QUOTES) ?></title>
<style>
  :root { color-scheme: light dark; }
  body { font: 16px/1.5 system-ui, sans-serif; max-width: 720px; margin: 3rem auto; padding: 0 1rem; }
  h1 { font-size: 1.4rem; margin-bottom: .25rem; }
  p.sub { color: #888; margin-top: 0; }
  #status { font-size: .85rem; color: #888; margin: .5rem 0; }
  .dot { display: inline-block; width: .6rem; height: .6rem; border-radius: 50%; margin-right: .4rem; vertical-align: middle; }
  .up { background: #2ecc71; } .down { background: #e74c3c; }
  #comments { list-style: none; padding: 0; }
  #comments li { padding: .6rem .8rem; border: 1px solid #8882; border-radius: .5rem; margin: .5rem 0; }
  #comments .meta { font-size: .8rem; color: #888; }
  #comments .who { font-weight: 600; color: inherit; }
  li.fresh { animation: pop .6s ease; }
  @keyframes pop { from { background: #2ecc7133; } to { background: transparent; } }
  form { display: grid; gap: .5rem; margin-top: 1.5rem; border-top: 1px solid #8882; padding-top: 1rem; }
  input, textarea { font: inherit; padding: .5rem .6rem; border: 1px solid #8888; border-radius: .5rem; background: transparent; color: inherit; }
  button { font: inherit; padding: .5rem 1rem; border: 0; border-radius: .5rem; background: #2d6cdf; color: #fff; cursor: pointer; }
  code { background: #8882; padding: .1rem .3rem; border-radius: .25rem; }
</style>
</head>
<body>
  <h1>Live comments: <?= htmlspecialchars($title, ENT_QUOTES) ?></h1>
  <p class="sub">Subscribed to <code>comments:<?= $post_id ?></code> over a native WebSocket.
     History comes from <code>wp_comments</code> (Turso); new comments arrive live via
     <code>ephpm_ws_broadcast()</code> from <code>post-comment.php</code>. Open this page in two
     tabs and post below.</p>
  <p id="status"><span class="dot down"></span>connecting...</p>

  <ul id="comments"></ul>

  <form id="form">
    <input id="author" type="text" placeholder="Your name" required maxlength="120">
    <textarea id="content" rows="2" placeholder="Say something..." required maxlength="2000"></textarea>
    <button type="submit">Post comment</button>
  </form>

  <script>
    const postId = <?= $post_id ?>;
    const channel = 'comments:' + postId;
    const wsUrl = (location.protocol === 'https:' ? 'wss' : 'ws') + '://' + location.host + '/?channel=' + encodeURIComponent(channel);
    const statusEl = document.getElementById('status');
    const list = document.getElementById('comments');
    const seen = new Set();
    let ws;

    function setStatus(up, text) {
      statusEl.innerHTML = '<span class="dot ' + (up ? 'up' : 'down') + '"></span>' + text;
    }

    function escapeHtml(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    function addComment(c, fresh) {
      if (seen.has(c.id)) return;
      seen.add(c.id);
      const li = document.createElement('li');
      if (fresh) li.className = 'fresh';
      li.innerHTML = '<span class="who">' + escapeHtml(c.author) + '</span> ' +
        '<span class="meta">' + escapeHtml(c.date) + '</span><br>' + escapeHtml(c.content);
      list.appendChild(li);
    }

    function connect() {
      ws = new WebSocket(wsUrl);
      ws.onopen = () => setStatus(true, 'connected - ' + wsUrl);
      ws.onclose = () => { setStatus(false, 'disconnected - retrying...'); setTimeout(connect, 1000); };
      ws.onmessage = (ev) => {
        let msg; try { msg = JSON.parse(ev.data); } catch (e) { return; }
        if (msg.type === 'history') {
          for (const c of (msg.comments || [])) addComment(c, false);
        } else if (msg.type === 'comment') {
          addComment(msg.comment, true);
        }
      };
    }

    document.getElementById('form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const author = document.getElementById('author');
      const content = document.getElementById('content');
      const body = new URLSearchParams({ post: postId, author: author.value, content: content.value });
      const res = await fetch('post-comment.php', { method: 'POST', body });
      if (res.ok) { content.value = ''; }
      else { alert('Could not post comment'); }
    });

    connect();
  </script>
</body>
</html>
