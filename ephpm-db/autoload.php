<?php
// Minimal autoloader for the vendored ephpm/db-wordpress classes. wp-config.php
// points EPHPM_DB_AUTOLOAD here so the wp-content/db.php drop-in can find them
// without Composer. All files live INSIDE the docroot (open_basedir-safe).
require_once __DIR__ . "/src/DbOpsInterface.php";
require_once __DIR__ . "/src/SapiDbOps.php";
require_once __DIR__ . "/src/PdoSqliteDbOps.php";
require_once __DIR__ . "/src/Db.php";
