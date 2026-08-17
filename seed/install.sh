#!/usr/bin/env bash
#
# Populate a running ePHPm preview site into the full showcase: a magazine
# theme, ~10 wp.org plugins, 150+ posts with featured images, hundreds of
# comments, pages, a nav menu, a WooCommerce store, and a sample Elementor
# page.
#
# Everything DB-facing runs INSIDE ePHPm through the wp-content/db.php drop-in
# (the ephpm_db_* SAPI functions only exist in-process), so this script only
# (a) downloads theme/plugin zips from wp.org into wp-content, and
# (b) drives the in-docroot seed/*.php generators over HTTP with a shared
#     one-shot token.
#
# Env:
#   BASE   base URL of the running site      (default http://127.0.0.1:8100)
#   HOST   Host header / vhost               (required if BASE is loopback)
#   DOCROOT  site document root              (required: where wp-content lives)
#   THEME  wp.org theme slug                 (default colormag)
#
# The generators are gated by EPHPM_SEED_TOKEN. This script mints a random
# token, exports it (so the ePHPm process the generator runs in can read it —
# set it in the site's environment/ini), and passes it as ?k=.
set -euo pipefail

BASE="${BASE:-http://127.0.0.1:8100}"
HOST="${HOST:-}"
DOCROOT="${DOCROOT:?set DOCROOT to the site document root}"
THEME="${THEME:-colormag}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TOKEN="${EPHPM_SEED_TOKEN:-$(head -c18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')}"

hdr=(); [ -n "$HOST" ] && hdr=(-H "Host: $HOST" -H "X-Forwarded-Proto: https")
get(){ curl -fsS "${hdr[@]}" "$BASE/$1"; }

echo ">> installing theme: $THEME"
curl -fsSL "https://downloads.wordpress.org/theme/${THEME}.zip" -o /tmp/t.zip
unzip -q -o /tmp/t.zip -d "$DOCROOT/wp-content/themes"

echo ">> installing plugins"
grep -vE '^\s*#|^\s*$' "$HERE/plugins.txt" | while read -r slug; do
  echo "   - $slug"
  curl -fsSL "https://downloads.wordpress.org/plugin/${slug}.zip" -o /tmp/p.zip
  unzip -q -o /tmp/p.zip -d "$DOCROOT/wp-content/plugins"
done

cat <<EOF

Theme + plugins are on disk. Finish activation and content from the running
ePHPm process (so activation hooks + inserts go through the drop-in -> Turso):

  1. Ensure the site's PHP environment exports:  EPHPM_SEED_TOKEN=$TOKEN
  2. Activate the theme + plugins and generate content by driving the
     token-gated seed endpoints, e.g.:

     k=$TOKEN
     get "seed/content.php?k=\$k&a=taxonomy"
     get "seed/content.php?k=\$k&a=authors"
     for i in 1 2 3; do get "seed/content.php?k=\$k&a=posts&n=55&img=1"; done
     get "seed/content.php?k=\$k&a=comments&n=220"
     get "seed/content.php?k=\$k&a=pages"
     get "seed/content.php?k=\$k&a=menu"
     get "seed/store.php?k=\$k&a=cats"
     get "seed/store.php?k=\$k&a=pages"
     get "seed/store.php?k=\$k&a=products&n=45&img=1"
     get "seed/store.php?k=\$k&a=orders&n=8"
     get "seed/elementor.php?k=\$k"

  (Theme + plugin activation itself is done with wp-cli or the WordPress
   admin; every activation hook then runs through the drop-in.)
EOF
