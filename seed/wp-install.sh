#!/usr/bin/env bash
#
# Install WordPress on a running ePHPm preview — over HTTP, through the
# wp-content/db.php drop-in.
#
# WHY NOT `wp core install`
# -------------------------
# wp-cli runs as a CLI process. In ePHPm's per-site (multi-tenant) mode a
# vhost's database handle only exists inside a REQUEST: `Router::resolve_site`
# maps the request's `Host` to a site key, and that is the *only* thing that
# opens `<db.sqlite.dir>/<site-key>.db`. A CLI process has no Host, so it has
# no site, so it has no database:
#
#     $ cd /srv/ephpm/sites/<site>
#     $ ephpm php -r 'ephpm_db_query("SELECT 1");'
#     Fatal error: ephpm_db: no embedded database is active (requires [db.sqlite])
#
# `ephpm php` has no --site/--config flag to stand in for a request
# (ephpm/ephpm#471), so *every* wp-cli database call fails the same way and
# `wp core install` can never succeed on a per-site node. It is not a missing
# PHP binary: the `wp` and `composer` wrappers exec `ephpm php <phar>` and both
# start fine — they just have no tenant.
#
# WordPress's own web installer, by contrast, IS a request. It resolves the
# site, opens the database and works, which is the same rule every `seed/*.php`
# generator in this repo already follows. So we POST its step-2 form.
#
# WHY THIS SCRIPT MUST NOT WRITE TO stdout/stderr WHEN RUN AS A SEED STEP
# ----------------------------------------------------------------------
# switchboard spawns `seed:` commands with *both* stdout and stderr set to
# pipes and then drops the read ends before waiting (tokio's `Command::status()`
# closes the parent's handles). The first byte a seed step writes to fd 1 or 2
# therefore raises SIGPIPE and kills it — exit 141, in single-digit
# milliseconds, logged only as "seed step failed — continuing". That is why the
# manifest redirects this script to a log file:
#
#     seed:
#       - "HOST=$PREVIEW_HOST bash seed/wp-install.sh >> .seed.log 2>&1"
#
# Keep the redirect. Run by hand without it and the output is yours as normal.
#
# Env:
#   HOST            Host header / vhost                (required)
#   BASE            base URL of the running site       (default: autodetected
#                   from the node's local ePHPm listener, then $PREVIEW_URL)
#   TITLE           site title                         (default "ePHPm Preview")
#   ADMIN_USER      admin username                     (default "admin")
#   ADMIN_EMAIL     admin email                        (default preview@example.com)
#   ADMIN_PASSWORD  admin password                     (default: freshly random)
#   CREDS_FILE      where to record the credentials    (default ./.wp-admin-credentials)
#   BLOG_PUBLIC     discourage search engines when 0   (default 0)
set -euo pipefail

HOST="${HOST:?set HOST to the preview vhost (switchboard provides \$PREVIEW_HOST)}"
TITLE="${TITLE:-ePHPm Preview}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_EMAIL="${ADMIN_EMAIL:-preview@example.com}"
CREDS_FILE="${CREDS_FILE:-.wp-admin-credentials}"
BLOG_PUBLIC="${BLOG_PUBLIC:-0}"

say() { echo "[wp-install $(date -u +%H:%M:%SZ)] $*"; }

# ── locate the site ─────────────────────────────────────────────────────────
# Prefer the node's own loopback listener with a Host header: it is up the
# instant the docroot is swapped in, whereas the public $PREVIEW_URL depends on
# DNS/TLS for a brand-new hostname and is routinely unreachable for the first
# minute of a deploy (switchboard's own health check spends that minute
# failing). $PREVIEW_URL stays in the list as a last resort.
probe() { curl -fsS -m 10 -o /dev/null -H "Host: $HOST" "$1/wp-admin/install.php" 2>/dev/null; }

if [ -n "${BASE:-}" ]; then
  CANDIDATES="$BASE"
else
  CANDIDATES="http://127.0.0.1:8080 http://127.0.0.1:8100 http://127.0.0.1:80 ${PREVIEW_URL:-}"
fi

BASE=""
for _try in 1 2 3 4 5 6 7 8 9 10; do
  for cand in $CANDIDATES; do
    [ -n "$cand" ] || continue
    if probe "$cand"; then BASE="$cand"; break; fi
  done
  if [ -n "$BASE" ]; then break; fi
  say "no listener served /wp-admin/install.php yet (attempt $_try) — retrying"
  sleep 3
done

if [ -z "$BASE" ]; then
  say "FAILED: none of these served /wp-admin/install.php for $HOST: $CANDIDATES"
  exit 1
fi
say "installer reachable at $BASE (Host: $HOST)"

# ── installer state ─────────────────────────────────────────────────────────
# `needs-install`  the step-1 form is being served (weblog_title input present)
# `installed`      WordPress refuses to reinstall ("Already Installed")
# `unknown`        neither — treated as not-safe-to-install
#
# The already-installed page is ~1.5 KB and carries no `name="weblog_title"`;
# the step-1 form does. (An `id="weblog_title"` reference appears in the footer
# script of both, hence matching on `name=`.)
fetch_install_page() {
  curl -fsS -m 30 -H "Host: $HOST" -H "X-Forwarded-Proto: https" \
    "$BASE/wp-admin/install.php"
}

state() {
  local body
  body="$(fetch_install_page)" || { echo "unknown"; return 0; }
  case "$body" in
    *'name="weblog_title"'*) echo "needs-install" ;;
    *'Already Installed'*)   echo "installed" ;;
    *)                       echo "unknown" ;;
  esac
}

st="$(state)"
if [ "$st" = "installed" ]; then
  say "WordPress is already installed — nothing to do (redeploys keep the"
  say "per-site database, only the docroot is replaced). The admin password"
  say "was set at the FIRST install and is not reset here; set ADMIN_PASSWORD"
  say "if you need a known one, or remove the site's .db to start over."
  exit 0
fi
if [ "$st" != "needs-install" ]; then
  say "FAILED: $BASE/wp-admin/install.php served neither the setup form nor the"
  say "'Already Installed' page. Refusing to POST an install into an unknown state."
  exit 1
fi

# ── stagger concurrent nodes ────────────────────────────────────────────────
# Every node in the preview cluster deploys the same PR independently and runs
# this seed step within a second or so of its peers. All of them talk to the
# *same* database (in per-site clustered mode a non-owner forwards every
# ephpm_db_* statement to the site's HRW owner, so reads are consistent too),
# and wp_install() is not concurrency-safe — two simultaneous runs would each
# insert the sample post/page/comment. A random stagger plus a re-check
# immediately before the POST narrows the window to the sub-second span of the
# install itself. It does not eliminate it; worst case a preview shows the
# "Hello world!" post twice, which is cosmetic.
jitter=$(( $(od -An -N2 -tu2 < /dev/urandom | tr -d ' ') % 25 ))
say "waiting ${jitter}s before installing (cluster stagger)"
sleep "$jitter"

st="$(state)"
if [ "$st" = "installed" ]; then
  say "another node installed WordPress while we waited — nothing to do"
  exit 0
fi

# ── password ────────────────────────────────────────────────────────────────
# Generated per install, never committed. An operator can pin one by exporting
# ADMIN_PASSWORD (switchboard passes its own environment through to seed steps).
if [ -z "${ADMIN_PASSWORD:-}" ]; then
  ADMIN_PASSWORD="$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | cut -c1-20)"
  [ -n "$ADMIN_PASSWORD" ] || { say "FAILED: could not generate a password"; exit 1; }
  say "generated a random admin password"
else
  say "using the ADMIN_PASSWORD from the environment"
fi

# ── install ─────────────────────────────────────────────────────────────────
# X-Forwarded-Proto: https so WordPress records https:// URLs even though this
# request rides the node's plain-HTTP listener. `pw_weak=1` accepts whatever
# password we were given; the installer otherwise rejects "weak" ones.
say "POSTing $BASE/wp-admin/install.php?step=2"
resp="$(curl -fsS -m 120 \
  -H "Host: $HOST" -H "X-Forwarded-Proto: https" \
  --data-urlencode "weblog_title=$TITLE" \
  --data-urlencode "user_name=$ADMIN_USER" \
  --data-urlencode "admin_password=$ADMIN_PASSWORD" \
  --data-urlencode "admin_password2=$ADMIN_PASSWORD" \
  --data-urlencode "pw_weak=1" \
  --data-urlencode "admin_email=$ADMIN_EMAIL" \
  --data-urlencode "blog_public=$BLOG_PUBLIC" \
  --data-urlencode "Submit=Install WordPress" \
  "$BASE/wp-admin/install.php?step=2")" || {
    say "FAILED: the installer POST did not return successfully"
    exit 1
  }

case "$resp" in
  *'Already Installed'*)
    say "another node won the race — WordPress is installed, our password was not used"
    exit 0
    ;;
esac

# ── verify ──────────────────────────────────────────────────────────────────
# Trust the post-condition, not the response body: the installer answers 200
# for both success and failure.
st="$(state)"
if [ "$st" != "installed" ]; then
  say "FAILED: after POSTing step=2 the installer still reports state '$st'"
  say "--- first 40 lines of the installer response ---"
  printf '%s\n' "$resp" | head -40
  exit 1
fi

if ! curl -fsS -m 30 -o /dev/null -H "Host: $HOST" -H "X-Forwarded-Proto: https" "$BASE/"; then
  say "FAILED: WordPress reports installed but the front page does not serve"
  exit 1
fi

# ── record the credentials ──────────────────────────────────────────────────
# Dot-prefixed on purpose: this docroot is the repository root, and ePHPm
# refuses (403) any request for a dot-prefixed path — verified against the
# preview nodes for .env and .switchboard/. Never printed to the deploy log.
umask 077
{
  echo "# ePHPm preview WordPress admin — generated at install, do not commit."
  echo "url=https://$HOST/wp-login.php"
  echo "user=$ADMIN_USER"
  echo "password=$ADMIN_PASSWORD"
  echo "email=$ADMIN_EMAIL"
} > "$CREDS_FILE"
chmod 600 "$CREDS_FILE" 2>/dev/null || true

say "WordPress installed. Admin credentials written to $CREDS_FILE (mode 600)."
say "Front page: https://$HOST/"
