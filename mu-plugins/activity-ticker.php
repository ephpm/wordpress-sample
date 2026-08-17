<?php
/**
 * Plugin Name: ePHPm Live Activity Ticker
 * Description: Site-wide real-time activity ticker over a native ePHPm WebSocket.
 *   A corner widget (injected on every front-end page) subscribes to the
 *   site-scoped `activity` channel; comments, new posts, WooCommerce orders and
 *   page views are broadcast to it from ordinary PHP via ephpm_ws_broadcast().
 *   The point: a public visitor sees the site pulse in real time.
 *
 * This is a mu-plugin so it cannot be deactivated and loads on every request.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Broadcast one activity event to every subscriber of the `activity` channel.
 * Best-effort: no-ops outside ePHPm or if the socket registry is unavailable.
 *
 * @param array<string,mixed> $event
 */
function ephpm_activity_broadcast(array $event): int
{
    if (!function_exists('ephpm_ws_broadcast')) {
        return 0;
    }
    try {
        return (int) ephpm_ws_broadcast('activity', json_encode([
            'type'  => 'event',
            'event' => $event,
        ]));
    } catch (\Throwable $e) {
        return 0;
    }
}

// ── New comment (WordPress-driven: admin, importer, generators) ──────────
add_action('wp_insert_comment', static function ($id, $comment): void {
    if (!$comment || (string) $comment->comment_approved !== '1') {
        return;
    }
    $title = get_the_title((int) $comment->comment_post_ID) ?: 'a post';
    ephpm_activity_broadcast([
        'kind'  => 'comment',
        'icon'  => 'C',
        'who'   => (string) $comment->comment_author,
        'what'  => 'commented on',
        'title' => $title,
        'url'   => get_permalink((int) $comment->comment_post_ID) ?: '/',
        'date'  => (string) $comment->comment_date,
    ]);
}, 10, 2);

// ── New published post ───────────────────────────────────────────────────
add_action('transition_post_status', static function ($new, $old, $post): void {
    if ($new !== 'publish' || $old === 'publish' || !$post || $post->post_type !== 'post') {
        return;
    }
    ephpm_activity_broadcast([
        'kind'  => 'post',
        'icon'  => 'P',
        'who'   => 'Editorial',
        'what'  => 'published',
        'title' => get_the_title($post) ?: 'a new story',
        'url'   => get_permalink($post) ?: '/',
        'date'  => (string) $post->post_date,
    ]);
}, 10, 3);

// ── WooCommerce order (only fires if WooCommerce is active) ───────────────
add_action('woocommerce_new_order', static function ($order_id): void {
    if (!function_exists('wc_get_order')) {
        return;
    }
    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }
    $item_name = 'an item';
    foreach ($order->get_items() as $item) {
        $item_name = $item->get_name();
        break;
    }
    ephpm_activity_broadcast([
        'kind'  => 'order',
        'icon'  => '$',
        'who'   => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) ?: 'Someone',
        'what'  => 'just bought',
        'title' => $item_name,
        'url'   => '/shop/',
        'date'  => current_time('mysql'),
    ]);
});

// ── Page-view "reading" pulse — makes the ticker move as visitors browse ──
add_action('template_redirect', static function (): void {
    if (is_admin() || wp_doing_ajax() || is_robots() || is_feed()) {
        return;
    }
    // Only the main document view, once per request.
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (is_singular()) {
        $title = get_the_title() ?: 'a page';
        $url   = get_permalink() ?: '/';
    } elseif (is_home() || is_front_page()) {
        $title = 'the front page';
        $url   = '/';
    } elseif (is_category() || is_tag() || is_archive()) {
        $title = wp_strip_all_tags(get_the_archive_title() ?: 'the archive');
        $url   = '/';
    } else {
        return;
    }

    ephpm_activity_broadcast([
        'kind'  => 'view',
        'icon'  => 'R',
        'who'   => 'A reader',
        'what'  => 'is viewing',
        'title' => $title,
        'url'   => $url,
        'date'  => current_time('mysql'),
    ]);
});

// ── The widget: injected on every front-end page ─────────────────────────
add_action('wp_footer', static function (): void {
    if (is_admin()) {
        return;
    }
    ?>
<style id="ephpm-activity-css">
  #ephpm-activity{position:fixed;right:16px;bottom:16px;width:330px;max-width:calc(100vw - 32px);z-index:99999;
    font:13px/1.45 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#0f172a;
    background:rgba(255,255,255,.92);backdrop-filter:blur(8px);border:1px solid rgba(15,23,42,.12);
    border-radius:14px;box-shadow:0 10px 30px rgba(2,6,23,.18);overflow:hidden;transition:transform .3s ease}
  #ephpm-activity.collapsed{transform:translateY(calc(100% - 42px))}
  #ephpm-activity header{display:flex;align-items:center;gap:.5rem;padding:10px 12px;cursor:pointer;
    background:linear-gradient(90deg,#4f46e5,#7c3aed);color:#fff;font-weight:600}
  #ephpm-activity header .live{display:inline-flex;align-items:center;gap:.4rem;margin-left:auto;font-size:11px;opacity:.9;font-weight:500}
  #ephpm-activity .dot{width:8px;height:8px;border-radius:50%;background:#ef4444}
  #ephpm-activity.on .dot{background:#22c55e;box-shadow:0 0 0 0 rgba(34,197,94,.7);animation:ephpm-pulse 1.8s infinite}
  @keyframes ephpm-pulse{0%{box-shadow:0 0 0 0 rgba(34,197,94,.6)}70%{box-shadow:0 0 0 8px rgba(34,197,94,0)}100%{box-shadow:0 0 0 0 rgba(34,197,94,0)}}
  #ephpm-activity ul{list-style:none;margin:0;padding:6px;max-height:290px;overflow-y:auto}
  #ephpm-activity li{display:flex;gap:.55rem;align-items:flex-start;padding:7px 8px;border-radius:9px}
  #ephpm-activity li+li{margin-top:2px}
  #ephpm-activity li.fresh{animation:ephpm-in .5s ease;background:rgba(124,58,237,.09)}
  @keyframes ephpm-in{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
  #ephpm-activity .ic{flex:0 0 22px;height:22px;border-radius:6px;display:grid;place-items:center;font-size:11px;font-weight:700;color:#fff;background:#64748b}
  #ephpm-activity li[data-k="comment"] .ic{background:#0ea5e9}
  #ephpm-activity li[data-k="post"] .ic{background:#7c3aed}
  #ephpm-activity li[data-k="order"] .ic{background:#16a34a}
  #ephpm-activity li[data-k="view"] .ic{background:#94a3b8}
  #ephpm-activity .tx{min-width:0}
  #ephpm-activity .tx b{font-weight:600}
  #ephpm-activity .tx a{color:#4f46e5;text-decoration:none}
  #ephpm-activity .tx .t{color:#475569;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;max-width:230px}
  @media (prefers-color-scheme:dark){
    #ephpm-activity{background:rgba(15,23,42,.92);color:#e2e8f0;border-color:rgba(148,163,184,.2)}
    #ephpm-activity .tx .t{color:#94a3b8}
    #ephpm-activity .tx a{color:#a5b4fc}
  }
</style>
<div id="ephpm-activity" class="collapsed" aria-live="polite">
  <header id="ephpm-activity-h">
    <span>Live activity</span>
    <span class="live"><span class="dot"></span><span id="ephpm-activity-s">connecting</span></span>
  </header>
  <ul id="ephpm-activity-l"></ul>
</div>
<script>
(function(){
  var box=document.getElementById('ephpm-activity'),
      list=document.getElementById('ephpm-activity-l'),
      statusEl=document.getElementById('ephpm-activity-s'),
      hdr=document.getElementById('ephpm-activity-h'),
      MAX=25, ws, opened=false;
  hdr.addEventListener('click',function(){box.classList.toggle('collapsed');});
  function esc(s){var d=document.createElement('div');d.textContent=(s==null?'':String(s));return d.innerHTML;}
  function row(e,fresh){
    var li=document.createElement('li');
    li.setAttribute('data-k', e.kind||'view');
    if(fresh) li.className='fresh';
    var icon=(e.icon||'*'); if(icon.indexOf('\\u')===0){icon='\u2609';}
    li.innerHTML='<span class="ic">'+esc(icon)+'</span>'+
      '<span class="tx"><b>'+esc(e.who||'Someone')+'</b> '+esc(e.what||'did something')+
      ' <a href="'+esc(e.url||'#')+'">'+'</a>'+
      '<span class="t">'+esc(e.title||'')+'</span></span>';
    list.insertBefore(li, list.firstChild);
    while(list.children.length>MAX) list.removeChild(list.lastChild);
  }
  function set(on,txt){ box.classList.toggle('on',on); statusEl.textContent=txt; }
  function connect(){
    var url=(location.protocol==='https:'?'wss':'ws')+'://'+location.host+'/?channel=activity';
    try{ ws=new WebSocket(url); }catch(e){ set(false,'offline'); return; }
    ws.onopen=function(){ set(true,'live'); };
    ws.onclose=function(){ set(false,'reconnecting'); setTimeout(connect,1500); };
    ws.onerror=function(){ try{ws.close();}catch(e){} };
    ws.onmessage=function(ev){
      var m; try{ m=JSON.parse(ev.data); }catch(e){ return; }
      if(m.type==='history'){
        (m.events||[]).slice().reverse().forEach(function(e){ row(e,false); });
        if(!opened && (m.events||[]).length){ opened=true; box.classList.remove('collapsed'); setTimeout(function(){box.classList.add('collapsed');},4200); }
      } else if(m.type==='event'){
        row(m.event,true);
        if(box.classList.contains('collapsed')){ box.classList.remove('collapsed'); setTimeout(function(){box.classList.add('collapsed');},4200); }
      }
    };
  }
  connect();
})();
</script>
    <?php
}, 99);
