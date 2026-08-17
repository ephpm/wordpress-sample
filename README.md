# wordpress-sample

A minimal, deployable **real WordPress** for ePHPm PR previews — and a live
demo of ePHPm's three embedded engines working together: the per-site
[Turso](https://github.com/tursodatabase) database, the embedded **KV store**
(WordPress object cache), and **native WebSockets**.

One shared WordPress core is served as any number of preview vhosts; each
request's `Host` maps to its own per-site Turso database through the
[`ephpm/db-wordpress`](https://github.com/ephpm/db-wordpress) drop-in — no
mysqli, no socket, no external database. The same process also serves the
object cache from the embedded KV store via
[`ephpm/cache-wordpress`](https://github.com/ephpm/cache-wordpress), and runs
per-event WebSocket handlers that query the same Turso database.

## What's in here

| Path | Purpose |
|------|---------|
| `assemble.sh` | Builds a deployable docroot: fetches WordPress core, lays down the config, drop-ins and demo pages. |
| `wp-config.php` | Dynamic-host, HTTPS-aware config. Derives `WP_HOME`/`WP_SITEURL` from the request `Host`, and the scheme from `X-Forwarded-Proto` (TLS is terminated at the edge). One template serves every preview host. |
| `dropin/db.php` | The `ephpm/db-wordpress` database drop-in — WordPress on per-site Turso (vendored). |
| `ephpm-db/src/*.php`, `ephpm-db/autoload.php` | The db drop-in's classes + its in-docroot autoloader (`EPHPM_DB_AUTOLOAD`). |
| `dropin/object-cache.php` | The `ephpm/cache-wordpress` object-cache drop-in — WordPress object cache on the embedded KV store (vendored). |
| `ephpm-cache/src/*.php`, `ephpm-cache/autoload.php` | The cache drop-in's classes + its in-docroot autoloader (`EPHPM_CACHE_AUTOLOAD`). |
| `websocket.php` | Native-WebSocket entrypoint (`[server] websocket_files`). Routes on `$_SERVER['WS_EVENT']`; queries the per-site Turso DB from socket events. |
| `demo-search.php` | Live post-title typeahead streamed over a WebSocket (searches `wp_posts`). |
| `demo-comments.php` | Live comments room for a post: history from `wp_comments`, new comments pushed live. |
| `post-comment.php` | Ordinary HTTP handler that inserts a comment and `ephpm_ws_broadcast()`s it to the room — the HTTP-to-socket showcase. |
| `ephpm.yaml` | Deploy manifest (php, docroot, `services: {database, kv, websocket}`, seed, health, ini). |
| `ephpm.json` | Legacy preview metadata: `{ "seed": "wp-install", "php": "8.5" }`. |

WordPress core itself is **not committed** — `assemble.sh` fetches it, keeping
this repo lean and always current.

## The three-engine demo

Once a site is assembled and seeded, three pages show all three engines at once
— every one of them talking to the **real WordPress database** through the
ePHPm SAPI, with no polling:

- **`/demo-search.php`** — a search box whose every keystroke sends
  `{"action":"search","q":...}` over `new WebSocket("wss://<host>/")`.
  `websocket.php` queries `wp_posts` (published posts, `post_title LIKE`) in the
  per-site Turso database and streams the matches back. Live typeahead over the
  WordPress content, no REST round trips.
- **`/demo-comments.php?post=1`** — opens
  `wss://<host>/?channel=comments:1`. On connect, `websocket.php` subscribes the
  socket to that channel and replays recent approved comments from
  `wp_comments`. Post a comment with the form and it `POST`s to
  `post-comment.php`, which inserts into `wp_comments` **and**
  `ephpm_ws_broadcast()`s the new comment to `comments:1` — so every open tab
  renders it instantly. Open the page in two tabs to watch it fan out.
- The **object cache** runs underneath all of it: `wp-content/object-cache.php`
  serves WordPress's persistent object cache from the embedded KV store
  (`ephpm_kv_*`), shared across requests.

`websocket.php` requires `[server.websocket] enabled = true` in the ePHPm config
(the `websocket_files` entrypoint defaults to `["websocket.php"]`). All three
engines degrade gracefully: if KV is unavailable the object cache falls back to
WordPress's built-in runtime cache rather than fataling.

## The one gotcha: the drop-in must live INSIDE the docroot

ePHPm multi-tenant mode confines every vhost with `open_basedir =
<sites_dir>/<site>` plus the vhost's private temp dir. A `wp-content/db.php`
**symlinked** to a shared external checkout is therefore **denied** by
open_basedir — the `require` fails, the drop-in does nothing, and WordPress
silently falls back to the stock mysqli `wpdb`, which then errors with *"Error
establishing a database connection."*

The fix (what `assemble.sh` does): the drop-in **and its classes** are copied in
as **real files** under the docroot, and `wp-config.php` points
`EPHPM_DB_AUTOLOAD` at the in-docroot `ephpm-db/autoload.php`.

## Deploy one preview site

```bash
# 1. Assemble a docroot at the site's sites_dir slot.
./assemble.sh /srv/ephpm-sites/ephpm-wordpress-sample-pr-1

# 2. Start ePHPm with a preview config (sites_dir, [db.sqlite].dir, preview=true).

# 3. Seed the per-site database ONCE by driving the WP web installer over HTTP.
curl -s -H 'Host: ephpm-wordpress-sample-pr-1.example.com' \
  --data-urlencode 'weblog_title=ePHPm Preview' \
  --data-urlencode 'user_name=admin' \
  --data-urlencode 'admin_password=<pw>' \
  --data-urlencode 'admin_password2=<pw>' \
  --data-urlencode 'pw_weak=1' \
  --data-urlencode 'admin_email=admin@example.com' \
  --data-urlencode 'blog_public=0' \
  --data-urlencode 'Submit=Install WordPress' \
  'http://127.0.0.1:8100/wp-admin/install.php?step=2'
```

A default `wp core install` ships one post (Hello World), one page (Sample
Page), and one comment — enough to drive the front page, a permalink, and the
REST API.
