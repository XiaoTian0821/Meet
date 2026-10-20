<?php
/**
 * ConnectMeet - Participants API
 *
 *   POST /api/participants.php?action=join        join a meeting (obtain credential)
 *   POST /api/participants.php?action=leave        leave the meeting
 *   POST /api/participants.php?action=heartbeat    update presence
 *   POST /api/participants.php?action=remove       host removes a participant
 *   GET  /api/participants.php?action=list          list active participants
 *   GET  /api/participants.php?action=state&meeting=CODE   fetch meeting + participants
 *
 * Join issues a per-participant credential (participant_token) which the
 * client stores and echoes on subsequent authenticated operations.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$cmMethod = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$cmBody = cm_read_json_body();
cm_db_required();

switch ($action) {
    case 'join':
        if ($cmMethod !== 'POST') { cm_error(405, 'Method not allowed.'); }
        cm_api_join($cmBody);
        break;
    case 'leave':
        if ($cmMethod !== 'POST') { cm_error(405, 'Method not allowed.'); }
        cm_api_leave($cmBody);
        break;
    case 'heartbeat':
        if ($cmMethod !== 'POST') { cm_error(405, 'Method not allowed.'); }
        cm_api_heartbeat($cmBody);
        break;
    case 'remove':
        if ($cmMethod !== 'POST') { cm_error(405, 'Method not allowed.'); }
        cm_api_remove($cmBody);
        break;
    case 'list':
    case 'state':
        cm_api_state($cmBody, $action);
        break;
    default:
        cm_error(400, 'Unknown participants action.');
}

// ---------------------------------------------------------------------------

function cm_lookup_meeting_by_code(string $code): ?array
{
    global $CM_PDO;
    $stmt = $CM_PDO->prepare('SELECT * FROM meetings WHERE meeting_code=?');
    $stmt->execute([trim($code)]);
    return $stmt->fetch() ?: null;
}

function cm_api_join(array $b): void
{
    global $CM_PDO, $CM_CONFIG;

    if (!cm_rate_limit('join', 40, 60)) {
        cm_throttle('Too many join attempts.');
    }

    $code = trim((string)($b['meeting'] ?? ''));
    if ($code === '') {
        cm_bad('Meeting code is required.');
    }
    $m = cm_lookup_meeting_by_code($code);
    if (!$m) {
        cm_notfound('Meeting not found. This code does not exist.');
    }

    $now = time();
    if ($m['expires_at'] && strtotime($m['expires_at']) < $now) {
        $CM_PDO->prepare('UPDATE meetings SET status="expired" WHERE id=?')->execute([(int)$m['id']]);
        cm_error(410, 'This meeting has expired.');
    }
    if ($m['status'] === 'ended') {
        cm_error(410, 'This meeting has ended.');
    }
    if ($m['status'] === 'locked' || $m['is_locked']) {
        cm_error(423, 'This meeting is locked. Ask the host to unlock it.');
    }

    // Host joining via host_token gets the host role; everyone else is a participant.
    $hostToken = (string)($b['host_token'] ?? '');
    $isHost = cm_is_host((int)$m['id'], $hostToken);

    // Participant limit check (count active, non-stale).
    $staleSec = (int)cm_setting('stale_participant_sec', 30);
    $stmt = $CM_PDO->prepare(
        'SELECT COUNT(*) AS c FROM meeting_participants
         WHERE meeting_id=? AND left_at IS NULL
           AND (last_seen_at IS NULL OR last_seen_at >= (NOW() - INTERVAL ? SECOND))'
    );
    $stmt->execute([(int)$m['id'], $staleSec]);
    $active = (int)$stmt->fetch()['c'];
    $limit = (int)$m['participant_limit'];
    if ($active >= $limit && !$isHost) {
        cm_conflict('This meeting is full (' . $limit . ' participants).');
    }

    // Display name validation.
    $maxName = (int)cm_setting('max_display_name_len', 40);
    $name = cm_sanitize_name((string)($b['display_name'] ?? ''), $maxName);
    if (mb_strlen($name) < 1) {
        cm_bad('Please enter a display name.');
    }

    $participantToken = cm_random_hex(24);
    $userId = cm_auth_user()['id'] ?? null;

    // Duplicate-join prevention: if this IP + meeting already has a live
    // participant with the same display name, don't create a duplicate.
    // (We allow multiple names so a person can join with two profiles.)

    try {
        $ins = $CM_PDO->prepare(
            'INSERT INTO meeting_participants
             (meeting_id, user_id, participant_token_hash, display_name, role,
              audio_enabled, video_enabled, hand_raised, joined_at, last_seen_at)
             VALUES (?,?,?,?,?,?,?,0,NOW(),NOW())'
        );
        $ins->execute([
            (int)$m['id'],
            $userId,
            cm_hash_token($participantToken),
            $name,
            $isHost ? 'host' : 'participant',
            (int)($b['audio_enabled'] ?? 1),
            (int)($b['video_enabled'] ?? 1),
        ]);
        $participantId = (int)$CM_PDO->lastInsertId();
    } catch (PDOException $e) {
        error_log('[ConnectMeet] participant insert error: ' . $e->getMessage());
        cm_server('Could not join the meeting. Please try again.');
        return;
    }

    // System event: participant joined.
    $CM_PDO->prepare(
        'INSERT INTO meeting_events (meeting_id, from_participant, to_participant, event_type, event_payload)
         VALUES (?, ?, NULL, "participant_joined", ?)'
    )->execute([(int)$m['id'], $participantId, json_encode(['name' => $name])]);

    cm_created([
        'meeting_id'           => (int)$m['id'],
        'meeting_code'         => $m['meeting_code'],
        'participant_id'       => $participantId,
        'participant_token'    => $participantToken, // return ONCE; client stores it
        'role'                 => $isHost ? 'host' : 'participant',
        'display_name'         => $name,
        'title'               => $m['title'],
        'participant_limit'    => $limit,
        'allow_waiting_room'   => (bool)$m['allow_waiting_room'],
        'waiting_room_open'    => (bool)$m['waiting_room_open'],
        'is_locked'            => (bool)$m['is_locked'],
        'ice_servers'         => cm_ice_config(),
        'join_url'           => cm_url('room.php?meeting=' . rawurlencode($m['meeting_code'])),
    ], 'Joined meeting.');
}

function cm_require_participant(array $b, string $field = 'participant_token'): ?array
{
    global $CM_PDO;
    $meetingId = (int)($b['meeting_id'] ?? 0);
    $token = (string)($b[$field] ?? '');
    if (!$meetingId || $token === '') {
        cm_unauth('Missing meeting or credential.');
    }
    $p = cm_get_participant($meetingId, $token);
    if (!$p) {
        cm_unauth('Invalid or expired credential. Please rejoin.');
    }
    return $p;
}

function cm_api_leave(array $b): void
{
    $p = cm_require_participant($b);
    global $CM_PDO;
    $upd = $CM_PDO->prepare(
        'UPDATE meeting_participants SET left_at=NOW(), audio_enabled=0, video_enabled=0, screen_sharing=0, hand_raised=0
         WHERE id=? AND left_at IS NULL'
    );
    $upd->execute([(int)$p['id']]);

    $CM_PDO->prepare(
        'INSERT INTO meeting_events (meeting_id, from_participant, to_participant, event_type, event_payload)
         VALUES (?, ?, NULL, "participant_left", ?)'
    )->execute([(int)$p['meeting_id'], (int)$p['id'], json_encode(['name' => $p['display_name'], 'reason' => 'left'])]);

    cm_ok([], 'You have left the meeting.');
}

function cm_api_heartbeat(array $b): void
{
    $p = cm_require_participant($b);
    global $CM_PDO;

    // Optionally update media state in the same call (lightweight presence sync).
    $audio = array_key_exists('audio_enabled', $b) ? (int)(bool)$b['audio_enabled'] : null;
    $video = array_key_exists('video_enabled', $b) ? (int)(bool)$b['video_enabled'] : null;
    $hand  = array_key_exists('hand_raised', $b) ? (int)(bool)$b['hand_raised'] : null;
    $sharing = array_key_exists('screen_sharing', $b) ? (int)(bool)$b['screen_sharing'] : null;

    $columns = ['audio_enabled' => $audio, 'video_enabled' => $video, 'hand_raised' => $hand, 'screen_sharing' => $sharing];
    $setParts = ['last_seen_at' => 'NOW()'];
    $args = [];
    foreach ($columns as $col => $val) {
        if ($val !== null) {
            $setParts[$col] = '?';
            $args[] = $val;
        }
    }
    $sql = 'UPDATE meeting_participants SET ' . implode(', ', array_map(fn($k, $v) => $k . '=' . $v, array_keys($setParts), array_values($setParts))) . ' WHERE id=? AND left_at IS NULL';
    $args[] = (int)$p['id'];
    $CM_PDO->prepare($sql)->execute($args);

    // If state actually changed, emit a broadcast state event.
    $changed = ($audio !== null && $audio !== (int)$p['audio_enabled'])
            || ($video !== null && $video !== (int)$p['video_enabled'])
            || ($hand !== null && $hand !== (int)$p['hand_raised'])
            || ($sharing !== null && $sharing !== (int)$p['screen_sharing']);
    if ($changed) {
        $CM_PDO->prepare(
            'INSERT INTO meeting_events (meeting_id, from_participant, to_participant, event_type, event_payload)
             VALUES (?, ?, NULL, "state_update", ?)'
        )->execute([(int)$p['meeting_id'], (int)$p['id'], json_encode([
            'audio_enabled'  => $audio !== null ? $audio : $p['audio_enabled'],
            'video_enabled'  => $video !== null ? $video : $p['video_enabled'],
            'hand_raised'    => $hand !== null ? $hand : $p['hand_raised'],
            'screen_sharing' => $sharing !== null ? $sharing : $p['screen_sharing'],
        ])]);
    }

    cm_ok(['last_seen' => date('Y-m-d H:i:s')], 'Presence updated.');
}

function cm_api_remove(array $b): void
{
    $host = cm_require_participant($b);
    cm_require_role($host, 'host'); // only hosts may remove
    global $CM_PDO;
    $targetId = (int)($b['target_participant_id'] ?? 0);
    if (!$targetId) {
        cm_bad('target_participant_id is required.');
    }
    // Cannot remove self via this path (use leave); cannot remove host.
    $t = $CM_PDO->prepare('SELECT * FROM meeting_participants WHERE id=? AND meeting_id=? AND left_at IS NULL');
    $t->execute([$targetId, (int)$host['meeting_id']]);
    $target = $t->fetch();
    if (!$target) {
        cm_notfound('Participant not found.');
    }
    if ($target['role'] === 'host') {
        cm_forbidden('The host cannot be removed from the meeting.');
    }
    $CM_PDO->prepare('UPDATE meeting_participants SET left_at=NOW() WHERE id=?')->execute([$targetId]);
    $CM_PDO->prepare(
        'INSERT INTO meeting_events (meeting_id, from_participant, to_participant, event_type, event_payload)
         VALUES (?, ?, ?, "participant_removed", ?)'
    )->execute([(int)$host['meeting_id'], (int)$host['id'], $targetId, json_encode(['name' => $target['display_name']])]);
    cm_ok([], 'Participant removed.');
}

function cm_api_state(array $b, string $action): void
{
    global $CM_PDO;

    // For `list` (used by the authenticated room poller), require the
    // participant credential so only members can read the roster.
    // `state` accepts a public meeting-code query for initial hand-off.
    if ($action === 'list') {
        $meetingId = (int)($_GET['meeting_id'] ?? 0);
        $token = (string)($_GET['participant_token'] ?? ($_POST['participant_token'] ?? ''));
        if (!$meetingId) { cm_bad('meeting_id is required.'); }
        $p = cm_get_participant($meetingId, $token);
        if (!$p) { cm_unauth('Invalid or expired credential. Please rejoin.'); }
    }

    $meetingId = (int)($b['meeting_id'] ?? $_GET['meeting_id'] ?? 0);
    $code = trim((string)($b['meeting'] ?? ($_GET['meeting'] ?? ($action === 'state' ? ($_GET['meeting'] ?? '') : ''))));
    if (!$meetingId && $code !== '') {
        $m = cm_lookup_meeting_by_code($code);
        $meetingId = $m ? (int)$m['id'] : 0;
    }
    if (!$meetingId) {
        cm_bad('meeting_id or meeting code is required.');
    }
    $m = $CM_PDO->prepare('SELECT id, meeting_code, title, status, is_locked, participant_limit, allow_waiting_room, waiting_room_open, expires_at FROM meetings WHERE id=?');
    $m->execute([$meetingId]);
    $meeting = $m->fetch();
    if (!$meeting) {
        cm_notfound('Meeting not found.');
    }

    $staleSec = (int)cm_setting('stale_participant_sec', 30);
    $p = $CM_PDO->prepare(
        'SELECT id, display_name, role, audio_enabled, video_enabled, hand_raised, screen_sharing, joined_at, last_seen_at
         FROM meeting_participants
         WHERE meeting_id=? AND left_at IS NULL
           AND (last_seen_at IS NULL OR last_seen_at >= (NOW() - INTERVAL ? SECOND))
         ORDER BY (role="host") DESC, joined_at ASC'
    );
    $p->execute([$meetingId, $staleSec]);
    $participants = $p->fetchAll();

    // Meeting status events (for a poller that hasn't seen them).
    cm_ok([
        'meeting'      => $meeting,
        'participants' => $participants,
        'count'        => count($participants),
        'limit'        => (int)$meeting['participant_limit'],
    ], 'Meeting state.');
}
