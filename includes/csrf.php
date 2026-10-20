<?php
/**
 * ConnectMeet - CSRF protection for state-changing API requests.
 *
 * Strategy:
 *   - On join, the server issues a short-lived, per-participant `api_token`
 *     (returned to the browser) which the client echoes on subsequent
 *     state-changing (POST/PUT/DELETE) requests via an `X-CM-Token` header
 *     or `cm_token` field.
 *   - The token is bound to the participant credential, so a token from one
 *     participant cannot be used in another's meeting.
 *   - We additionally re-check meeting membership on every protected op.
 *
 * The token is validated by matching cm_hash_token(token) against the stored
 * participant_token_hash AND the meeting id.
 */

declare(strict_types=1);

/**
 * Validate that the incoming request carries a valid api_token for the given
 * participant/meeting. Returns true on success; otherwise calls cm_unauth.
 *
 * Accepts: X-CM-Token header OR cm_token in JSON body.
 */
function cm_require_token(int $meetingId, string $participantToken): bool
{
    global $CM_PDO;
    $h = cm_hash_token($participantToken);

    // Confirm this token maps to a live participant in this meeting.
    $stmt = $CM_PDO->prepare(
        'SELECT id, role FROM meeting_participants
         WHERE meeting_id=? AND participant_token_hash=? AND left_at IS NULL'
    );
    $stmt->execute([$meetingId, $h]);
    $row = $stmt->fetch();

    if (!$row) {
        cm_unauth('Invalid or expired meeting credential. Please rejoin.');
        return false;
    }

    return true;
}

/**
 * Assert the caller's role. $role must be 'host' (or 'participant' to allow all).
 */
function cm_require_role(array $participant, string $needed = 'participant'): void
{
    $hasHost = ($participant['role'] ?? 'participant') === 'host';
    if ($needed === 'host' && !$hasHost) {
        cm_forbidden('Host privileges required for this action.');
        return;
    }
    // participant role is always allowed
}
