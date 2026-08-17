<?php
// Minimal autoloader for the vendored ephpm/cache-wordpress classes.
// wp-config.php points EPHPM_CACHE_AUTOLOAD here so the
// wp-content/object-cache.php drop-in can find them without Composer. All
// files live INSIDE the docroot (open_basedir-safe).
require_once __DIR__ . "/src/KvOpsInterface.php";
require_once __DIR__ . "/src/InMemoryKvOps.php";
require_once __DIR__ . "/src/SapiKvOps.php";
require_once __DIR__ . "/src/ObjectCache.php";
