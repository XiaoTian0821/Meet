<?php
/**
 * ConnectMeet - Signaling API (SDP offer/answer + ICE candidates).
 *
 *   POST /api/signaling.php?action=send   send an offer/answer/ice event
 *   GET  /api/signaling.php?action=poll     fetch pending signaling events
 *        (long-poll when `wait=1` with `timeout` ms)
 *
 * All operations require a valid participant credential for the meeting.
 * Signaling payloads (SDP, ICE) are transient and stored in meeting_events;
 * a poller claims and marks them delivered so they are not re-sent.
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
        cm_api_send($cmBody);
        break;
    case 'poll':
        cm_api_poll($cmBody);
        break;
    default:
        cm_error(400, 'Unknown signaling action.');
}

// ---------------------------------------------------------------------------

function cm_require_participant_or_unauth(array $b): array
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

function cm_api_send(array $b): void
{
    $p = cm_require_participant_or_unauth($b);
    global $CM_PDO;

    // Rate limit signaling sends.
    if (!cm_rate_limit('signal_send', 120, 30)) {
        cm_throttle('Signaling rate limit exceeded.');
    }

    $kind = (string)($b['kind'] ?? ''); // offer | answer | ice | control | screen_share | screen_stop
    $eventTypes = ['offer', 'answer', 'ice', 'control', 'screen_share', 'screen_stop'];
    if (!in_array($kind, $eventTypes, true)) {
        cm_bad('Unknown signaling kind. Use one of: ' . implode(', ', $eventTypes));
    }

    $toId = isset($b['to']) ? (int)$b['to'] : 0; // 0 = broadcast
    $payload = $b['payload'] ?? [];

    // For unicast (offer/answer/ice), verify the target is a live participant.
    if ($toId !== 0) {
        $t = $CM_PDO->prepare('SELECT id FROM meeting_participants WHERE id=? AND meeting_id=? AND left_at IS NULL');
        $t->execute([$toId, (int)$p['meeting_id']]);
        if (!$t->fetch()) {
            cm_notfound('Target participant no longer in the meeting.');
        }
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $stmt = $CM_PDO->prepare(
        'INSERT INTO meeting_events (meeting_id, from_participant, to_participant, event_type, event_payload)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([(int)$p['meeting_id'], (int)$p['id'], $toId ?: null, $kind, $json]);

    cm_ok(['event_id' => (int)$CM_PDO->lastInsertId()], 'Event queued.');
}

/**
 * Poll for signaling events addressed to this participant (unicast) plus
 * broadcast signaling/control events. Long-poll supported via wait/timeout.
 */
function cm_api_poll(array $b): void
{
    // Poll is a GET endpoint, so accept credentials from its query string.
    if (!isset($b['meeting_id'])) {
        $b['meeting_id'] = $_GET['meeting_id'] ?? null;
    }
    if (!isset($b['participant_token'])) {
        $b['participant_token'] = $_GET['participant_token'] ?? null;
    }
    $p = cm_require_participant_or_unauth($b);
    global $CM_PDO;

    $meetingId = (int)$p['meeting_id'];
    $selfId = (int)$p['id'];

    $maxWaitMs = (int)($_GET['timeout'] ?? 1500);
    $wait = (isset($_GET['wait']) && $_GET['wait'] === '1');
    $maxPolls = $wait ? 20 : 1; // 20 * ~150ms ≈ 3s max per long-poll
    $deadline = time() * 1000 + $maxWaitMs;

    $all = [];
    for ($i = 0; $i < $maxPolls; $i++) {
        $stmt = $CM_PDO->prepare(
            'SELECT id, event_type AS event, event_payload, from_participant AS sender_id
             FROM meeting_events
             WHERE meeting_id=? AND (to_participant=? OR to_participant IS NULL)
               AND from_participant != ?
               AND delivered_at IS NULL
             ORDER BY id ASC
             LIMIT 50'
        );
        $stmt->execute([$meetingId, $selfId, $selfId]);
        $rows = $stmt->fetchAll();

        if (!empty($rows)) {
            // Claim them (mark delivered) so they are not replayed.
            $ids = array_map(fn($r) => (int)$r['id'], $rows);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $claim = $CM_PDO->prepare(
                "UPDATE meeting_events SET delivered_at=NOW() WHERE meeting_id=? AND id IN ($placeholders) AND delivered_at IS NULL"
            );
            $claim->execute(array_merge([$meetingId], $ids));

            foreach ($rows as $r) {
                $payload = json_decode($r['event_payload'], true);
                $all[] = [
                    'id'         => (int)$r['id'],
                    'event'      => $r['event'],
                    'from'       => $r['sender_id'] ? (int)$r['sender_id'] : null,
                    'payload'    => $payload,
                    'timestamp'  => date('c'),
                ];
            }
            break;
        }

        if (!$wait) {
            break;
        }
        // Long-poll: sleep a bit and retry until deadline.
        $nowMs = time() * 1000;
        if ($nowMs >= $deadline) {
            break;
        }
        usleep(120000); // 120ms
    }

    cm_ok([
        'events'    => $all,
        'meeting_id'=> $meetingId,
        'polling_ms'=> cm_setting('polling_interval_ms', '2000'),
        'now'       => date('c'),
    ], 'Events returned.');
}
