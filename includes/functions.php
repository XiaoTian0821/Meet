<?php
/**
 * ConnectMeet - shared helper functions.
 */

declare(strict_types=1);

/**
 * Ensure the PDO handle is available; otherwise emit a graceful 503 JSON.
 */
function cm_db_required(): void
{
    global $CM_PDO;
    if ($CM_PDO === null) {
        cm_error(503, 'Database is not available. Import database/install.sql and check config/database.php.');
    }
}

/**
 * Read JSON request body. Returns [] on missing/invalid.
 * Enforces a request size cap to avoid unbounded parsing.
 */
function cm_read_json_body(): array
{
    $max = 1_000_000; // 1 MB cap; SDP payloads are well under this
    $raw = file_get_contents('php://input', false, null, 0, $max + 1);
    if ($raw === false) {
        return [];
    }
    if (strlen($raw) > $max) {
        return []; // oversized -> treat as invalid
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Client IP (best-effort; trust only a configured proxy header in production).
 */
function cm_client_ip(): string
{
    // Default to the real TCP peer. Only use forwarded headers if explicitly
    // trusted via config to avoid spoofing.
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Short hex fingerprint of the client IP for rate-limit buckets.
 */
function cm_ip_bucket(): string
{
    return substr(hash('sha256', cm_client_ip() . '|' . ($CM_APP_SECRET ?? '')), 0, 16);
}

/**
 * Constant-time safe random hex of given byte length.
 */
function cm_random_hex(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

/**
 * Hash a participant credential token (we store the hash, not the token).
 */
function cm_hash_token(string $token): string
{
    return hash('sha256', $token . '|' . ($GLOBALS['CM_APP_SECRET'] ?? ''));
}

/**
 * Sanitize a display name: strip control chars, collapse spaces, cap length.
 */
function cm_sanitize_name(string $name, int $maxLen = 40): string
{
    $name = trim($name);
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
    $name = preg_replace('/\s+/u', ' ', $name);
    $name = mb_substr((string)$name, 0, $maxLen);
    return $name;
}

/**
 * HTML-escape for output. Use on any string going into HTML.
 */
function cm_esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Get a runtime setting from the app_settings table, with a fallback.
 * Cached per-request to avoid repeated queries.
 */
function cm_setting(string $key, $default = null)
{
    global $CM_PDO;
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            $rows = $CM_PDO->query('SELECT `key`,`value` FROM app_settings')->fetchAll();
            foreach ($rows as $r) {
                $cache[$r['key']] = $r['value'];
            }
        } catch (Throwable $e) {
            error_log('[ConnectMeet] app_settings read failed: ' . $e->getMessage());
        }
    }
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

/**
 * Sliding-window rate limiter keyed by (ipBucket, operation).
 * Returns true if the request is within the allowed budget, false if throttled.
 */
function cm_rate_limit(string $operation, int $limit, int $windowSec = 30): bool
{
    global $CM_PDO;
    try {
        $bucket = cm_ip_bucket();
        $pdo = $CM_PDO;
        $pdo->beginTransaction();

        $q = $pdo->prepare('SELECT hits, window_start FROM rate_limits WHERE bucket=? AND operation=? FOR UPDATE');
        $q->execute([$bucket, $operation]);
        $row = $q->fetch();

        $now = date('Y-m-d H:i:s');
        if (!$row || strtotime($now) - strtotime($row['window_start'] ?? $now) >= $windowSec) {
            // New window (or no record yet): start fresh with 1 hit.
            if ($row) {
                $u = $pdo->prepare('UPDATE rate_limits SET hits=1, window_start=? WHERE bucket=? AND operation=?');
                $u->execute([$now, $bucket, $operation]);
            } else {
                $i = $pdo->prepare('INSERT INTO rate_limits (bucket, operation, window_start, hits) VALUES (?,?,?,1)');
                $i->execute([$bucket, $operation, $now]);
            }
            $pdo->commit();
            return true;
        }

        // Within window: bump the counter.
        $pdo->commit();
        $bump = $pdo->prepare('UPDATE rate_limits SET hits = hits + 1 WHERE bucket=? AND operation=? AND window_start=?');
        $bump->execute([$bucket, $operation, $row['window_start']]);
        // The hit that just occurred is now counted; allow up to $limit total.
        return (int)$row['hits'] < $limit;
    } catch (Throwable $e) {
        // Fail open so legitimate use isn't blocked on a transient DB error,
        // but log it.
        error_log('[ConnectMeet] rate_limit error: ' . $e->getMessage());
        return true;
    }
}

/**
 * Verify a participant belongs to a meeting and is not stale.
 * Returns the participant row or null.
 */
function cm_get_participant(int $meetingId, string $token): ?array
{
    global $CM_PDO;
    $h = cm_hash_token($token);
    $stmt = $CM_PDO->prepare(
        'SELECT mp.*, m.status AS m_status, m.is_locked, m.participant_limit
         FROM meeting_participants mp
         JOIN meetings m ON m.id = mp.meeting_id
         WHERE mp.meeting_id = ? AND mp.participant_token_hash = ? AND mp.left_at IS NULL'
    );
    $stmt->execute([$meetingId, $h]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    // Stale check: if last_seen_at is older than the threshold, treat as gone.
    $staleSec = (int)(cm_setting('stale_participant_sec', 30));
    if ($row['last_seen_at']) {
        if ((time() - strtotime($row['last_seen_at'])) > $staleSec) {
            return null;
        }
    }
    return $row;
}

/**
 * Verify host token for a meeting (guest-host mode). Returns true if the token
 * matches the stored host hash for this meeting.
 */
function cm_is_host(int $meetingId, ?string $hostToken): bool
{
    global $CM_PDO;
    if (!$hostToken) {
        return false;
    }
    $h = cm_hash_token($hostToken);
    $stmt = $CM_PDO->prepare('SELECT host_token_hash FROM meetings WHERE id=?');
    $stmt->execute([$meetingId]);
    $row = $stmt->fetch();
    return $row && ($row['host_token_hash'] ?? null) === $h;
}

/**
 * Prune stale participants in a meeting (last_seen older than threshold).
 * Marks them left. Returns number pruned.
 */
function cm_prune_stale(int $meetingId): int
{
    global $CM_PDO;
    $staleSec = (int)(cm_setting('stale_participant_sec', 30));
    $stmt = $CM_PDO->prepare(
        'UPDATE meeting_participants
         SET left_at = NOW()
         WHERE meeting_id = ? AND left_at IS NULL
           AND (last_seen_at IS NULL OR last_seen_at < (NOW() - INTERVAL ? SECOND))'
    );
    $stmt->execute([$meetingId, $staleSec]);
    return $stmt->rowCount();
}
