<?php
/**
 * ConnectMeet - Meeting API
 * Endpoints (JSON, POST unless noted):
 *   POST /api/meetings.php?action=create      create a meeting (instant or scheduled)
 *   GET  /api/meetings.php?action=validate&meeting=CODE   validate a meeting code
 *   POST /api/meetings.php?action=lock        lock/unlock a meeting (host)
 *   POST /api/meetings.php?action=waiting     set waiting-room state (host)
 *   POST /api/meetings.php?action=end         end meeting for everyone (host)
 *
 * Every response uses the standard envelope {success, data, message}.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$cmMethod = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$cmBody = ($cmMethod === 'POST') ? cm_read_json_body() : [];
cm_db_required();

// CORS (same-origin default; optional allow-list)
cm_send_cors();

switch ($action) {
    case 'create':
        if ($cmMethod !== 'POST') {
            cm_error(405, 'Method not allowed.');
        }
        cm_api_create_meeting($cmBody);
        break;

    case 'validate':
        $code = $_GET['meeting'] ?? '';
        cm_api_validate_meeting($code);
        break;

    case 'lock':
        if ($cmMethod !== 'POST') {
            cm_error(405, 'Method not allowed.');
        }
        cm_api_lock_meeting($cmBody);
        break;

    case 'waiting':
        if ($cmMethod !== 'POST') {
            cm_error(405, 'Method not allowed.');
        }
        cm_api_waiting_room($cmBody);
        break;

    case 'end':
        if ($cmMethod !== 'POST') {
            cm_error(405, 'Method not allowed.');
        }
        cm_api_end_meeting($cmBody);
        break;

    default:
        cm_error(400, 'Unknown meetings action. Use create|validate|lock|waiting|end.');
}

// ---------------------------------------------------------------------------

function cm_send_cors(): void
{
    global $CM_CONFIG;
    $allowed = $CM_CONFIG['cors']['allowed_origins'] ?? [];
    if (!empty($allowed)) {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (in_array($origin, $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }
    }
    // Same-origin requests need no CORS headers. Never use "*".
}

function cm_api_create_meeting(array $b): void
{
    global $CM_PDO, $CM_CONFIG;

    // Rate limit meeting creation per IP.
    if (!cm_rate_limit('meeting_create', 20, 60)) {
        cm_throttle('Too many meeting creations. Try again later.');
    }

    $title = $b['title'] ?? '';
    if (mb_strlen(trim($title)) === 0) {
        cm_bad('Meeting title is required.');
    }
    $title = cm_sanitize_name($title, 128);

    $limit = (int)($b['participant_limit'] ?? $CM_CONFIG['app']['meeting']['default_participant_limit']);
    $maxLimit = (int)$CM_CONFIG['app']['meeting']['max_participant_limit'];
    if ($limit < 2 || $limit > $maxLimit) {
        cm_bad('Participant limit must be between 2 and ' . $maxLimit . '.');
    }

    $scheduled = $b['scheduled_at'] ?? null; // ISO or null
    $scheduledAt = null;
    if (!empty($scheduled)) {
        try {
            $scheduledAt = date('Y-m-d H:i:s', strtotime($scheduled));
        } catch (Throwable $e) {
            cm_bad('Invalid scheduled_at date/time.');
        }
        // A scheduled meeting must be in the future.
        if (strtotime($scheduledAt) < time() + 60) {
            cm_bad('Scheduled time must be at least 1 minute in the future.');
        }
    }

    $expiresHours = (int)($CM_CONFIG['app']['meeting']['expiry_hours'] ?? 168);
    $expiryBase = $scheduledAt ? strtotime($scheduledAt) : time();
    $expiresAt = date('Y-m-d H:i:s', $expiryBase + ($expiresHours * 3600));

    $isHostedByAccount = (cm_auth_user() !== null);
    $hostUserId = $isHostedByAccount ? (int)cm_auth_user()['id'] : null;

    $meetingCode = cm_generate_code();
    $hostTokenHash = null;
    $status = 'active';
    if ($scheduledAt) {
        $status = 'scheduled';
    }

    // Host token: issued once to the creator (guest-host mode). If an account
    // host, the account id already authorizes host actions, but we still store
    // a host token hash so the creator's browser can act as host.
    $hostToken = cm_random_hex(24);

    try {
        $stmt = $CM_PDO->prepare(
            'INSERT INTO meetings (meeting_code, host_user_id, host_token_hash, title,
                status, scheduled_at, expires_at, is_locked, participant_limit, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $meetingCode,
            $hostUserId,
            cm_hash_token($hostToken),
            $title,
            $status,
            $scheduledAt,
            $expiresAt,
            0,
            $limit,
            $hostUserId,
        ]);
        $meetingId = (int)$CM_PDO->lastInsertId();
    } catch (PDOException $e) {
        // Rare unique-code collision.
        $mysqlError = $e->errorInfo[1] ?? null;
        if ($e->getCode() === '23000' && (int)$mysqlError === 1062) {
            cm_api_create_meeting($b); // regenerate and retry (bounded by rarity)
            return;
        }
        error_log('[ConnectMeet] meeting insert error: ' . $e->getMessage());
        cm_server('Could not create the meeting. Please try again.');
        return;
    }

    $joinUrl = cm_url('room.php?meeting=' . rawurlencode($meetingCode));

    cm_created([
        'meeting_id'       => $meetingId,
        'meeting_code'     => $meetingCode,
        'host_token'       => $hostToken, // returned ONCE; client must keep it
        'title'            => $title,
        'status'           => $status,
        'scheduled_at'     => $scheduledAt,
        'expires_at'       => $expiresAt,
        'participant_limit'=> $limit,
        'join_url'         => $joinUrl,
    ], 'Meeting created.');
}

function cm_api_validate_meeting(string $code): void
{
    global $CM_PDO;
    $code = trim($code);
    if ($code === '') {
        cm_bad('Meeting code is required.');
    }
    $stmt = $CM_PDO->prepare('SELECT * FROM meetings WHERE meeting_code=?');
    $stmt->execute([$code]);
    $m = $stmt->fetch();

    if (!$m) {
        cm_notfound('Meeting not found. Check the code and try again.');
    }

    $now = time();
    $expired = $m['expires_at'] && strtotime($m['expires_at']) < $now;
    if ($expired && $m['status'] !== 'ended') {
        $upd = $CM_PDO->prepare('UPDATE meetings SET status="expired" WHERE id=?');
        $upd->execute([(int)$m['id']]);
        $m['status'] = 'expired';
    }

    $joinable = !in_array($m['status'], ['ended', 'expired'], true);
    $locked = (bool)$m['is_locked'];

    cm_ok([
        'meeting_id'        => (int)$m['id'],
        'meeting_code'     => $m['meeting_code'],
        'title'            => $m['title'],
        'status'           => $m['status'],
        'scheduled_at'     => $m['scheduled_at'],
        'expires_at'       => $m['expires_at'],
        'is_locked'        => $locked,
        'waiting_room'     => (bool)$m['allow_waiting_room'],
        'participant_limit'=> (int)$m['participant_limit'],
        'joinable'         => $joinable,
        'join_url'         => cm_url('room.php?meeting=' . rawurlencode($m['meeting_code'])),
    ], $joinable ? 'Meeting is joinable.' : 'Meeting is not joinable.');
}

function cm_api_lock_meeting(array $b): void
{
    global $CM_PDO;
    $meetingId = (int)($b['meeting_id'] ?? 0);
    $hostToken = $b['host_token'] ?? '';
    if (!$meetingId || !cm_is_host($meetingId, $hostToken)) {
        cm_forbidden('Host access required to lock or unlock a meeting.');
    }
    $locked = (int)($b['locked'] ?? 1);
    $upd = $CM_PDO->prepare('UPDATE meetings SET is_locked=? WHERE id=?');
    $upd->execute([$locked ? 1 : 0, $meetingId]);
    cm_ok(['is_locked' => (bool)$locked], $locked ? 'Meeting locked.' : 'Meeting unlocked.');
}

function cm_api_waiting_room(array $b): void
{
    global $CM_PDO;
    $meetingId = (int)($b['meeting_id'] ?? 0);
    $hostToken = $b['host_token'] ?? '';
    if (!$meetingId || !cm_is_host($meetingId, $hostToken)) {
        cm_forbidden('Host access required to manage the waiting room.');
    }
    $enable = (int)($b['enable'] ?? 0);
    $open = (int)($b['waiting_room_open'] ?? 0);
    $upd = $CM_PDO->prepare('UPDATE meetings SET allow_waiting_room=?, waiting_room_open=? WHERE id=?');
    $upd->execute([$enable ? 1 : 0, $open ? 1 : 0, $meetingId]);
    cm_ok(['allow_waiting_room' => (bool)$enable, 'waiting_room_open' => (bool)$open], 'Waiting room updated.');
}

function cm_api_end_meeting(array $b): void
{
    global $CM_PDO;
    $meetingId = (int)($b['meeting_id'] ?? 0);
    $hostToken = $b['host_token'] ?? '';
    if (!$meetingId || !cm_is_host($meetingId, $hostToken)) {
        cm_forbidden('Only the host can end the meeting for everyone.');
    }
    $upd = $CM_PDO->prepare('UPDATE meetings SET status="ended" WHERE id=?');
    $upd->execute([$meetingId]);

    // Broadcast a control event so all connected clients are informed.
    $payload = json_encode(['reason' => 'ended_by_host']);
    $ins = $CM_PDO->prepare(
        'INSERT INTO meeting_events (meeting_id, from_participant, to_participant, event_type, event_payload)
         VALUES (?, NULL, NULL, "meeting_ended", ?)'
    );
    $ins->execute([$meetingId, $payload]);

    cm_ok([], 'Meeting ended for everyone.');
}

/**
 * Generate a unique, human-readable meeting code. Uses cryptographically random
 * bytes via random_bytes. Format: 3-4-4 from the configured alphabet.
 */
function cm_generate_code(): string
{
    global $CM_PDO, $CM_CONFIG;
    if ($CM_PDO === null) {
        cm_error(503, 'Database is not available. Please import the schema and check your configuration.');
    }
    $alpha = $CM_CONFIG['app']['meeting']['code_chars'] ?? 'abcdefghijkmnpqrstuvwxyz23456789';
    $len = $CM_CONFIG['app']['meeting']['code_lengths'] ?? [3, 4, 4];

    for ($attempt = 0; $attempt < 8; $attempt++) {
        $parts = [];
        foreach ($len as $n) {
            $s = '';
            $bytes = random_bytes($n);
            for ($i = 0; $i < $n; $i++) {
                $s .= $alpha[ord($bytes[$i]) % strlen($alpha)];
            }
            $parts[] = $s;
        }
        $code = implode('-', $parts);

        // Ensure uniqueness.
        $chk = $CM_PDO->prepare('SELECT id FROM meetings WHERE meeting_code=?');
        $chk->execute([$code]);
        if (!$chk->fetch()) {
            return $code;
        }
    }
    cm_server('Could not generate a unique meeting code. Try again.');
    return '';
}
