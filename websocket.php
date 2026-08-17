<?php
/**
 * ePHPm WebSocket entrypoint for the wordpress-sample three-engine demo.
 *
 * One script, every event — userland routes on $_SERVER['WS_EVENT']
 * ('connect' | 'message' | 'disconnect'), the ePHPm native-WebSocket
 * contract. This runs on the same handle_php path as an ordinary HTTP
 * request, so the per-site Turso database (ephpm_db_query/ephpm_db_execute)
 * and the embedded KV store (ephpm_kv_*) are wired exactly as they are for
 * wp-content/db.php — a socket event queries the real WordPress database.
 *
 * Referenced by [server] websocket_files (defaults to ["websocket.php"]).
 *
 * Channels:
 *   ?channel=comments:<postid>   live comment room for a post (demo-comments.php)
 *
 * Messages (one JSON frame per message, {"action":...}):
 *   {"action":"search","q":"..."}   live typeahead over published posts
 */

$event = $_SERVER['WS_EVENT'] ?? '';
$conn  = $_SERVER['WS_CONNECTION_ID'] ?? '';

/** Query wp_* safely; never let a DB error tear down the socket session. */
function ws_db_query(string $sql, array $params = []): array
{
    try {
        return ephpm_db_query($sql, $params);
    } catch (\Throwable $e) {
        return [];
    }
}

if ($event === 'connect') {
    // Accept the upgrade. A non-2xx response here refuses the socket.
    http_response_code(200);

    parse_str($_SERVER['QUERY_STRING'] ?? '', $q);
    $channel = isset($q['channel']) ? (string) $q['channel'] : '';

    // Site-wide live activity ticker: ?channel=activity.
    // Seeded with recent real events (comments + posts) so a fresh visitor
    // sees the site's pulse immediately; new events arrive via
    // ephpm_ws_broadcast('activity', ...) from ordinary HTTP requests and
    // WordPress hooks (see wp-content/mu-plugins/activity-ticker.php).
    if ($channel === 'activity') {
        ephpm_ws_subscribe('activity');

        $events = [];

        // Recent comments with their post title.
        $rows = ws_db_query(
            'SELECT c.comment_ID, c.comment_author, c.comment_content, c.comment_date, '
            . 'p.post_title, p.ID AS post_id '
            . 'FROM wp_comments c JOIN wp_posts p ON p.ID = c.comment_post_ID '
            . "WHERE c.comment_approved = '1' AND p.post_status = 'publish' "
            . 'ORDER BY c.comment_date DESC, c.comment_ID DESC LIMIT 8'
        );
        foreach ($rows as $r) {
            $events[] = [
                'kind'  => 'comment',
                'icon'  => 'C',
                'who'   => (string) $r['comment_author'],
                'what'  => 'commented on',
                'title' => (string) $r['post_title'],
                'url'   => '/?p=' . (int) $r['post_id'],
                'date'  => (string) $r['comment_date'],
            ];
        }

        // Recent published posts.
        $rows = ws_db_query(
            'SELECT ID, post_title, post_date FROM wp_posts '
            . "WHERE post_status = 'publish' AND post_type = 'post' "
            . 'ORDER BY post_date DESC LIMIT 8'
        );
        foreach ($rows as $r) {
            $events[] = [
                'kind'  => 'post',
                'icon'  => 'P',
                'who'   => 'Editorial',
                'what'  => 'published',
                'title' => (string) $r['post_title'],
                'url'   => '/?p=' . (int) $r['ID'],
                'date'  => (string) $r['post_date'],
            ];
        }

        // Newest first, capped.
        usort($events, static fn ($a, $b) => strcmp($b['date'], $a['date']));
        $events = array_slice($events, 0, 12);

        ephpm_ws_send(json_encode([
            'type'    => 'history',
            'channel' => 'activity',
            'events'  => $events,
        ]));

        return;
    }

    // A live comments room: ?channel=comments:<postid>.
    if (preg_match('/^comments:(\d+)$/', $channel, $m)) {
        ephpm_ws_subscribe($channel);

        $post_id = (int) $m[1];
        $rows = ws_db_query(
            'SELECT comment_ID, comment_author, comment_content, comment_date '
            . 'FROM wp_comments '
            . "WHERE comment_post_ID = ? AND comment_approved = '1' "
            . 'ORDER BY comment_date ASC, comment_ID ASC LIMIT 50',
            [$post_id]
        );

        $comments = [];
        foreach ($rows as $r) {
            $comments[] = [
                'id'      => (int) $r['comment_ID'],
                'author'  => (string) $r['comment_author'],
                'content' => (string) $r['comment_content'],
                'date'    => (string) $r['comment_date'],
            ];
        }

        ephpm_ws_send(json_encode([
            'type'     => 'history',
            'channel'  => $channel,
            'comments' => $comments,
        ]));
    }

    return;
}

if ($event === 'message') {
    $raw = file_get_contents('php://input');
    $msg = json_decode((string) $raw, true);
    if (!is_array($msg)) {
        ephpm_ws_send(json_encode(['type' => 'error', 'error' => 'invalid frame']));
        return;
    }

    $action = (string) ($msg['action'] ?? '');

    if ($action === 'search') {
        $term = trim((string) ($msg['q'] ?? ''));
        $results = [];

        if ($term !== '') {
            // Bound parameter — the % / _ inside act as LIKE wildcards,
            // which is exactly what a typeahead wants.
            $like = '%' . $term . '%';
            $rows = ws_db_query(
                'SELECT ID, post_title FROM wp_posts '
                . "WHERE post_status = 'publish' AND post_type = 'post' "
                . 'AND post_title LIKE ? '
                . 'ORDER BY post_date DESC LIMIT 8',
                [$like]
            );
            foreach ($rows as $r) {
                $results[] = ['id' => (int) $r['ID'], 'title' => (string) $r['post_title']];
            }
        }

        ephpm_ws_send(json_encode([
            'type'    => 'search',
            'q'       => $term,
            'results' => $results,
        ]));
        return;
    }

    ephpm_ws_send(json_encode(['type' => 'error', 'error' => 'unknown action']));
    return;
}

// 'disconnect': channel subscriptions are dropped automatically when the
// connection leaves the registry — nothing to clean up here.
