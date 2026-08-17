<?php
/**
 * Dynamic-host wp-config.php for the ePHPm wordpress-sample preview workload.
 *
 * ONE shared WordPress core serves any number of preview vhosts. The per-site
 * divergence is the database, provided by ePHPm: each request's Host maps to
 * its own <db.sqlite.dir>/<site-key>.db via the ephpm/db-wordpress drop-in at
 * wp-content/db.php. A single seeded install therefore serves every preview
 * hostname with no cross-host redirect, because WP_HOME / WP_SITEURL are
 * derived from the request at runtime rather than baked into the database.
 *
 * TLS is terminated at the edge (e.g. a Cloudflare tunnel), so ePHPm itself
 * speaks plain HTTP. We reconstruct the external scheme from
 * X-Forwarded-Proto and set $_SERVER['HTTPS'] so is_ssl() is true and WordPress
 * emits https:// URLs (no redirect loop, no mixed content).
 */

// The drop-in intercepts all DB access; these constants must exist but their
// values are unused (no real MySQL is contacted).
define('DB_NAME', 'wordpress');
define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('DB_HOST', '127.0.0.1:3306');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

// External scheme from the edge proxy (Cloudflare sends X-Forwarded-Proto).
$__host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$__xfp  = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
$__proto = (strtolower(trim(explode(',', $__xfp)[0])) === 'https') ? 'https' : 'http';
if ($__proto === 'https') {
    // Make is_ssl() true so WordPress does not 301 back to http.
    $_SERVER['HTTPS'] = 'on';
}

// Per-tenant URL from the request — one template serves every preview host.
define('WP_HOME',    $__proto . '://' . $__host);
define('WP_SITEURL', $__proto . '://' . $__host);

// Preview determinism: no cron loopback, no external HTTP, no auto-update.
define('DISABLE_WP_CRON', true);
define('AUTOMATIC_UPDATER_DISABLED', true);
define('WP_HTTP_BLOCK_EXTERNAL', true);
define('WP_DEBUG', false);

// Throwaway preview salts. These are NOT secrets — every ephemeral preview
// site is disposable and holds no real user data. Rotate for anything durable.
define('AUTH_KEY',         'ephpm-preview-1');
define('SECURE_AUTH_KEY',  'ephpm-preview-2');
define('LOGGED_IN_KEY',    'ephpm-preview-3');
define('NONCE_KEY',        'ephpm-preview-4');
define('AUTH_SALT',        'ephpm-preview-5');
define('SECURE_AUTH_SALT', 'ephpm-preview-6');
define('LOGGED_IN_SALT',   'ephpm-preview-7');
define('NONCE_SALT',       'ephpm-preview-8');

// The db-wordpress drop-in and its classes MUST live inside the docroot:
// multi-tenant mode sets open_basedir to <sites_dir>/<site> (+ the private temp
// dir), so a drop-in symlinked to a shared external path is denied and
// WordPress silently falls back to mysqli ("Error establishing a database
// connection"). Point the drop-in at an autoloader that lives under this
// docroot (assemble.sh places it at <docroot>/ephpm-db/autoload.php).
define('EPHPM_DB_AUTOLOAD', __DIR__ . '/ephpm-db/autoload.php');

// The ephpm/cache-wordpress object-cache drop-in (wp-content/object-cache.php)
// finds its classes the same open_basedir-safe way: an autoloader that lives
// under this docroot (assemble.sh places it at <docroot>/ephpm-cache/autoload.php).
// This makes WordPress use ePHPm's embedded KV store for its persistent object
// cache. If ephpm_kv_* is unavailable the drop-in degrades to the built-in
// non-persistent cache instead of fataling.
define('EPHPM_CACHE_AUTOLOAD', __DIR__ . '/ephpm-cache/autoload.php');

$table_prefix = 'wp_';

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
require_once ABSPATH . 'wp-settings.php';
