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
| `post-comment.php` | Ordinary HTTP handler that inserts a comment and `ephpm_ws_broadcast()`s it to both the room and the site-wide `activity` channel. |
| `mu-plugins/activity-ticker.php` | Site-wide **live activity ticker**: a corner widget injected on every front-end page opens `wss://<host>/?channel=activity`; comments, new posts, WooCommerce orders and page views are broadcast to it from ordinary PHP via `ephpm_ws_broadcast()`. A public visitor sees the site pulse in real time. |
| `seed/*.php` | Token-gated content/store generators (`EPHPM_SEED_TOKEN`) run **through the drop-in** over HTTP: `content.php` (posts, GD featured images, comments, pages, nav menu), `store.php` (WooCommerce products + orders), `elementor.php` (a sample Elementor page). |
| `seed/wp-install.sh` | Installs WordPress by POSTing its own web installer (`/wp-admin/install.php?step=2`) — the only way that works on a per-site ePHPm node, see below. Idempotent; records the generated admin password in `.wp-admin-credentials`. |
| `seed/install.sh`, `seed/plugins.txt` | Downloads a magazine theme + ~10 wp.org plugins, then prints the generator recipe — the reproducible "make it busy" starting point. |
| `ephpm.yaml` | Deploy manifest (php, docroot, `services: {database, kv, websocket}`, seed, health, ini). |
| `ephpm.json` | Legacy preview metadata: `{ "seed": "wp-install", "php": "8.5" }`. |

## The full showcase

`seed/install.sh` + the `seed/*.php` generators turn a bare install into a
busy public magazine site to exercise the drop-in under a real plugin-heavy
workload: a magazine theme (ColorMag), ~10 activated plugins (Yoast SEO,
WooCommerce, Elementor, bbPress, Contact Form 7, WPForms, FooGallery,
Contextual Related Posts, WP-PageNavi, Classic Editor), 150+ posts with
generated featured images, hundreds of comments, ~15 pages, a nav menu, a
~45-product WooCommerce store with sample orders, and an Elementor-built page.

Every plugin activation hook and every insert runs through the
`ephpm/db-wordpress` drop-in against the embedded Turso engine — which is the
point: the MySQL DDL/DML those plugins emit (`ON UPDATE CURRENT_TIMESTAMP`,
`ALTER … CONVERT/CHANGE/MODIFY`, `TRUNCATE`, multi-table `DELETE`, `INSERT
IGNORE`, `ADD/DROP PRIMARY KEY`, `INFORMATION_SCHEMA` probes, `FROM dual`)
is translated to SQLite-compatible SQL by the drop-in so it all works on a
single embedded database.

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

## The other gotcha: `wp core install` cannot work here

wp-cli runs as a **CLI process**, and in ePHPm's per-site mode a vhost's
database handle only exists inside a **request**: `Router::resolve_site` maps
the request's `Host` to a site key, and that is the only thing that opens
`<db.sqlite.dir>/<site-key>.db`. A CLI process has no `Host`, so it has no
site, so it has no database:

```
$ cd /srv/ephpm/sites/<site> && ephpm php -r 'ephpm_db_query("SELECT 1");'
Fatal error: ephpm_db: no embedded database is active (requires [db.sqlite])
```

`ephpm php` has no `--site`/`--config` flag to stand in for a request
(ephpm/ephpm#471), so **every** wp-cli database call fails this way — including
`wp core install`, which is why previews used to land permanently on the
install screen. It is not a missing PHP binary: `wp` and `composer` on a
preview node are wrappers that exec `ephpm php <phar>` and both start fine,
they just have no tenant.

WordPress's own web installer *is* a request, so it works. `seed/wp-install.sh`
drives it, which is the same over-HTTP rule the `seed/*.php` generators follow.

## Deploy one preview site

```bash
# 1. Assemble a docroot at the site's sites_dir slot.
./assemble.sh /srv/ephpm-sites/ephpm-wordpress-sample-pr-1

# 2. Start ePHPm with a preview config (sites_dir, [db.sqlite].dir, preview=true).

# 3. Install WordPress ONCE by driving the WP web installer over HTTP. Safe to
#    re-run: it exits 0 without touching anything if WordPress is installed.
HOST=ephpm-wordpress-sample-pr-1.example.com \
BASE=http://127.0.0.1:8080 \
  bash seed/wp-install.sh
```

The generated admin password is written to `.wp-admin-credentials` (mode 600)
in the docroot — dot-prefixed, so ePHPm answers 403 for it. Export
`ADMIN_PASSWORD` to pin one instead. A redeploy replaces the docroot but keeps
the per-site database, so the password is **not** reset and that file is gone;
delete the site's `.db` to start over.

A default install ships one post (Hello World), one page (Sample Page), and one
comment — enough to drive the front page, a permalink, and the REST API.

### Seed steps must not write to stdout or stderr

switchboard spawns each `seed:` command with both stdout and stderr piped and
then drops the read ends before waiting, so the first byte a seed step writes
to fd 1 or 2 raises **SIGPIPE** and kills it — exit 141, in single-digit
milliseconds, logged only as `seed step failed — continuing`. Every seed step in
`ephpm.yaml` therefore ends in `>> .seed.log 2>&1`. Keep it.
