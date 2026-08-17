<?php
/**
 * Ordinary HTTP handler that posts a comment and pushes it to every live
 * WebSocket viewer — the HTTP-to-socket showcase of the three-engine demo.
 *
 * 1. INSERT the comment into the per-site Turso database (ephpm_db_execute).
 * 2. ephpm_ws_broadcast() the new comment to the post's channel so all
 *    clients subscribed via websocket.php (?channel=comments:<postid>)
 *    receive it live, with no polling.
 *
 * Not a WordPress request — it talks to the same per-site DB and the same
 * site-scoped socket registry directly through the ePHPm SAPI.
 */

header('Content-Type: application/json');

function fail(int $code, string $msg): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    fail(405, 'POST required');
}

$post_id = (int) ($_POST['post'] ?? 0);
$author  = trim((string) ($_POST['author'] ?? ''));
$content = trim((string) ($_POST['content'] ?? ''));

if ($post_id <= 0) {
    fail(400, 'missing post id');
}
if ($author === '' || $content === '') {
    fail(400, 'author and content are required');
}

// Clamp lengths so the demo can't be used to store huge payloads.
$author  = mb_substr($author, 0, 120);
$content = mb_substr($content, 0, 2000);

// The post must exist and be published.
try {
    $post = ephpm_db_query(
        "SELECT ID FROM wp_posts WHERE ID = ? AND post_status = 'publish' AND post_type = 'post' LIMIT 1",
        [$post_id]
    );
} catch (\Throwable $e) {
    fail(500, 'database error');
}
if (empty($post)) {
    fail(404, 'no such published post');
}

$now_local = date('Y-m-d H:i:s');
$now_gmt   = gmdate('Y-m-d H:i:s');
$ip        = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

try {
    $ok = ephpm_db_execute(
        'INSERT INTO wp_comments '
        . '(comment_post_ID, comment_author, comment_author_email, comment_author_url, '
        . 'comment_author_IP, comment_date, comment_date_gmt, comment_content, comment_karma, '
        . 'comment_approved, comment_agent, comment_type, comment_parent, user_id) '
        . "VALUES (?, ?, '', '', ?, ?, ?, ?, 0, '1', '', 'comment', 0, 0)",
        [$post_id, $author, $ip, $now_local, $now_gmt, $content]
    );
} catch (\Throwable $e) {
    fail(500, 'could not save comment');
}

$comment_id = (int) ($ok['last_insert_id'] ?? 0);

$comment = [
    'id'      => $comment_id,
    'author'  => $author,
    'content' => $content,
    'date'    => $now_local,
];

// Push to every subscriber of this post's channel. Works from an ordinary
// HTTP request because the socket registry is site-scoped and this request
// resolves to the same site as the upgrade did.
$delivered = 0;
try {
    $delivered = (int) ephpm_ws_broadcast('comments:' . $post_id, json_encode([
        'type'    => 'comment',
        'channel' => 'comments:' . $post_id,
        'comment' => $comment,
    ]));
} catch (\Throwable $e) {
    // Comment is saved; live fan-out is best-effort.
    $delivered = 0;
}

// Also fan out to the site-wide activity ticker (?channel=activity).
try {
    $title = 'a post';
    $tr = ephpm_db_query('SELECT post_title FROM wp_posts WHERE ID = ? LIMIT 1', [$post_id]);
    if (!empty($tr[0]['post_title'])) {
        $title = (string) $tr[0]['post_title'];
    }
    ephpm_ws_broadcast('activity', json_encode([
        'type'  => 'event',
        'event' => [
            'kind'  => 'comment',
            'icon'  => 'C',
            'who'   => $author,
            'what'  => 'commented on',
            'title' => $title,
            'url'   => '/?p=' . $post_id,
            'date'  => $now_local,
        ],
    ]));
} catch (\Throwable $e) {
    // best-effort
}

echo json_encode(['ok' => true, 'comment' => $comment, 'delivered' => $delivered]);
