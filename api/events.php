<?php
/**
 * ConnectMeet - Events API
 *
 *   POST /api/events.php?action=send     send a generic event (reactions,
 *                                        hand-raise/lower, screen state,
 *                                        captions, control messages)
 *   GET  /api/events.php?action=poll      poll recent events for the meeting
 *        (chat delivered via /api/chat.php; this is for system/control events)
 *
 * Reactions and hand-raise are throttled to avoid signaling storms.
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
        cm_api_send_event($cmBody);
        break;
    case 'poll':
        cm_api_poll_events($cmBody);
        break;
    default:
        cm_error(400, 'Unknown events action.');
}

// ---------------------------------------------------------------------------

function cm_require_participant_ev(array $b): array
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

function cm_api_send_event(array $b): void
{
    $p = cm_require_participant_ev($b);
    global $CM_PDO;

    $type = (string)($b['type'] ?? '');
    $allowed = ['reaction', 'hand_raise', 'hand_lower', 'screen_share', 'screen_stop', 'caption', 'control'];
    if (!in_array($type, $allowed, true)) {
        cm_bad('Unknown event type.');
    }

    // Reactions and hand events: throttle hard.
    if (in_array($type, ['reaction', 'hand_raise', 'hand_lower'], true)) {
        if (!cm_rate_limit('reaction', 40, 30)) {
            cm_throttle('Too many events. Please wait.');
        }
    }

    $payload = $b['payload'] ?? [];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $CM_PDO->prepare(
        'INSERT INTO meeting_events (meeting_id, from_participant, to_participant, event_type, event_payload)
         VALUES (?, ?, NULL, ?, ?)'
    )->execute([(int)$p['meeting_id'], (int)$p['id'], $type, $json]);

    // If a hand state change, persist it on the participant row.
    if ($type === 'hand_raise' || $type === 'hand_lower') {
        $on = $type === 'hand_raise' ? 1 : 0;
        $CM_PDO->prepare('UPDATE meeting_participants SET hand_raised=? WHERE id=?')->execute([$on, (int)$p['id']]);
    }

    cm_ok(['event_id' => (int)$CM_PDO->lastInsertId()], 'Event queued.');
}

function cm_api_poll_events(array $b): void
{
    if (!isset($b['meeting_id'])) {
        $b['meeting_id'] = $_GET['meeting_id'] ?? null;
    }
    if (!isset($b['participant_token'])) {
        $b['participant_token'] = $_GET['participant_token'] ?? null;
    }
    $p = cm_require_participant_ev($b);
    global $CM_PDO;

    $meetingId = (int)$p['meeting_id'];
    // Since-id polling: the client passes last_seen_event_id.
    $since = (int)($_GET['since'] ?? 0);
    if ($since <= 0) {
        $since = (int)$p['id']; // conservative: from this participant's row
    }

    $stmt = $CM_PDO->prepare(
        'SELECT id, event_type AS event, event_payload, from_participant AS sender_id, created_at
         FROM meeting_events
         WHERE meeting_id=? AND to_participant IS NULL AND id > ?
         ORDER BY id ASC LIMIT 100'
    );
    $stmt->execute([$meetingId, $since]);
    $rows = $stmt->fetchAll();

    $events = [];
    foreach ($rows as $r) {
        $events[] = [
            'id'      => (int)$r['id'],
            'event'   => $r['event'],
            'from'    => $r['sender_id'] ? (int)$r['sender_id'] : null,
            'payload' => json_decode($r['event_payload'], true),
            'time'    => $r['created_at'],
        ];
    }

    cm_ok(['events' => $events, 'latest_id' => $events ? (int)end($events)['id'] : $since], 'Events returned.');
}
