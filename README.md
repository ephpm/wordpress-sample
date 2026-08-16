# wordpress-sample

A minimal, deployable **real WordPress** for ePHPm PR previews. One shared
WordPress core is served as any number of preview vhosts; each request's `Host`
maps to its own per-site [Turso](https://github.com/tursodatabase) database
through the [`ephpm/db-wordpress`](https://github.com/ephpm/db-wordpress)
drop-in — no mysqli, no socket, no external database.

## What's in here

| Path | Purpose |
|------|---------|
| `assemble.sh` | Builds a deployable docroot: fetches WordPress core, lays down the config + drop-in. |
| `wp-config.php` | Dynamic-host, HTTPS-aware config. Derives `WP_HOME`/`WP_SITEURL` from the request `Host`, and the scheme from `X-Forwarded-Proto` (TLS is terminated at the edge). One template serves every preview host. |
| `dropin/db.php` | The `ephpm/db-wordpress` WordPress database drop-in (vendored). |
| `ephpm-db/src/*.php` | The drop-in's classes (vendored). |
| `ephpm-db/autoload.php` | Autoloader the drop-in is pointed at via `EPHPM_DB_AUTOLOAD`. |
| `ephpm.json` | Preview metadata: `{ "seed": "wp-install", "php": "8.5" }`. |

WordPress core itself is **not committed** — `assemble.sh` fetches it, keeping
this repo lean and always current.

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
