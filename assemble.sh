#!/usr/bin/env bash
# Assemble a deployable WordPress docroot for an ePHPm preview site.
#
# Usage: assemble.sh <docroot>
#
# Produces, at <docroot>:
#   - WordPress core (fetched from wordpress.org/latest, not committed here)
#   - wp-config.php            dynamic-host, HTTPS-aware (from this repo)
#   - wp-content/db.php        the ephpm/db-wordpress drop-in (vendored)
#   - ephpm-db/src/*.php       drop-in classes, INSIDE the docroot
#   - ephpm-db/autoload.php    autoloader the drop-in is pointed at
#
# The drop-in + classes MUST be real files under the docroot (not symlinks to a
# shared checkout): ePHPm multi-tenant mode confines each vhost with
# open_basedir, which denies a symlinked drop-in and silently drops WordPress
# back to mysqli. Everything here is copied in as a real file.
set -euo pipefail

DOCROOT="${1:?usage: assemble.sh <docroot>}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WP_VERSION="${WP_VERSION:-latest}"

mkdir -p "$DOCROOT"

if [ ! -f "$DOCROOT/wp-settings.php" ]; then
  echo "Fetching WordPress core ($WP_VERSION) into $DOCROOT ..."
  curl -fsSL "https://wordpress.org/${WP_VERSION}.tar.gz" \
    | tar xz -C "$DOCROOT" --strip-components=1
else
  echo "WordPress core already present in $DOCROOT (skipping fetch)."
fi

echo "Installing dynamic-host wp-config.php ..."
cp "$HERE/wp-config.php" "$DOCROOT/wp-config.php"

echo "Installing ephpm/db-wordpress drop-in (real files, in-docroot) ..."
mkdir -p "$DOCROOT/wp-content" "$DOCROOT/ephpm-db/src"
cp "$HERE/dropin/db.php"        "$DOCROOT/wp-content/db.php"
cp "$HERE/ephpm-db/src/"*.php   "$DOCROOT/ephpm-db/src/"
cp "$HERE/ephpm-db/autoload.php" "$DOCROOT/ephpm-db/autoload.php"

echo "Done. Docroot ready at: $DOCROOT"
echo "Seed the per-site database by running the WordPress web installer once:"
echo "  curl -H 'Host: <site>' --data-urlencode weblog_title=... \\"
echo "       'http://127.0.0.1:<port>/wp-admin/install.php?step=2'"
