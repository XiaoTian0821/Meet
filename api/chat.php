<?php
/**
 * ConnectMeet - Chat API
 *
 *   POST /api/chat.php?action=send      send a chat message (rate-limited, validated)
 *   GET  /api/chat.php?action=poll      fetch chat since an id (client keeps last id)
 *
 * Chat content is stored raw and only HTML-escaped at render time by the
 * client. We additionally cap length and enforce membership + rate limits.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$cmMethod = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'poll';
$cmBody = cm_read_json_body();
cm_db_required();

switch ($action) {
    case 'send':
        if ($cmMethod !== 'POST') { cm_error(405, 'Method not allowed.'); }
        cm_api_send_chat($cmBody);
        break;
    case 'poll':
        cm_api_poll_chat($cmBody);
        break;
    default:
        cm_error(400, 'Unknown chat action.');
}

// ---------------------------------------------------------------------------

function cm_require_participant_ch(array $b): array
{
    global $CM_PDO;
    $meetingId = (int)($b['meeting_id'] ?? 0);
    $token = (string)($b['participant_token'] ?? '');
    if (!$meetingId || $token === '') {
        cm_unauth('Missing meeting or credential.');
    }
    $p = cm_get_participant($meetingId, $token);
    if (!$p) {
        cm_unauth('Invalid or expired credential. Please rejoin.');
    }
    return $p;
}

function cm_api_send_chat(array $b): void
{
    $p = cm_require_participant_ch($b);
    global $CM_PDO;

    $maxLen = (int)cm_setting('max_chat_len', 1000);
    $rateLimit = (int)cm_setting('rate_limit_chat', 20);
    $rateWindow = (int)cm_setting('rate_limit_window_sec', 30);

    if (!cm_rate_limit('chat', $rateLimit, $rateWindow)) {
        cm_throttle('You are typing too fast. Please wait a moment.');
    }

    $content = (string)($b['content'] ?? '');
    // Trim and strip NUL bytes; enforce length.
    $content = trim($content);
    $content = str_replace(["\0"], '', $content);
    if (mb_strlen($content) === 0) {
        cm_bad('Message cannot be empty.');
    }
    if (mb_strlen($content) > $maxLen) {
        cm_bad('Message is too long (max ' . $maxLen . ' characters).');
    }

    $CM_PDO->prepare(
        'INSERT INTO meeting_messages (meeting_id, participant_id, message_type, message_content)
         VALUES (?, ?, "chat", ?)'
    )->execute([(int)$p['meeting_id'], (int)$p['id'], $content]);

    cm_ok([
        'id'         => (int)$CM_PDO->lastInsertId(),
        'sender'     => $p['display_name'],
        'content'    => $content,
        'time'       => date('H:i'),
        'role'       => $p['role'],
    ], 'Message sent.');
}

function cm_api_poll_chat(array $b): void
{
    if (!isset($b['meeting_id'])) {
        $b['meeting_id'] = $_GET['meeting_id'] ?? null;
    }
    if (!isset($b['participant_token'])) {
        $b['participant_token'] = $_GET['participant_token'] ?? null;
    }
    $p = cm_require_participant_ch($b);
    global $CM_PDO;

    $meetingId = (int)$p['meeting_id'];
    $since = (int)($_GET['since'] ?? 0);

    $stmt = $CM_PDO->prepare(
        'SELECT m.id, m.message_content AS content, m.created_at,
                p.display_name AS sender, p.role AS role
         FROM meeting_messages m
         LEFT JOIN meeting_participants p ON p.id = m.participant_id
         WHERE m.meeting_id=? AND m.message_type="chat" AND m.id > ?
         ORDER BY m.id ASC LIMIT 200'
    );
    $stmt->execute([$meetingId, $since]);
    $rows = $stmt->fetchAll();

    $messages = [];
    foreach ($rows as $r) {
        $messages[] = [
            'id'      => (int)$r['id'],
            'sender'  => $r['sender'] ?? 'System',
            'role'    => $r['role'] ?? 'participant',
            'content' => $r['content'],
            'time'    => substr($r['created_at'], 11, 5),
        ];
    }

    cm_ok([
        'messages'  => $messages,
        'latest_id' => $messages ? (int)end($messages)['id'] : $since,
    ], 'Messages returned.');
}
