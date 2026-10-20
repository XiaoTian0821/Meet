<?php
/**
 * ConnectMeet - optional account-based authentication.
 *
 * Accounts are OPTIONAL. The prototype primarily uses guest participation:
 *   - Guest meeting: create meeting -> get a host_token (returned once), join
 *     as participant with just a display name (gets a participant_token).
 *   - Account meeting (if accounts enabled): host_user_id is set, and
 *     host actions require the host user's session.
 *
 * This module guards the (optional) account login/logout and provides
 * session_regenerate to defeat fixation. It is intentionally minimal.
 */

declare(strict_types=1);

/**
 * Verify an account session by email + password. On success, regenerate the
 * session id (defeat fixation) and store the user. Returns user row or null.
 */
function cm_auth_login(string $email, string $password): ?array
{
    global $CM_PDO;
    $stmt = $CM_PDO->prepare('SELECT * FROM users WHERE email=? AND status="active" LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user || !$user['password_hash'] || !password_verify($password, $user['password_hash'])) {
        return null;
    }
    // Regenerate session id after successful login.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['cm_user'] = ['id' => (int)$user['id'], 'email' => $user['email']];
    return $user;
}

function cm_auth_logout(): void
{
    unset($_SESSION['cm_user']);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

/**
 * Currently logged-in user, or null.
 */
function cm_auth_user(): ?array
{
    return $_SESSION['cm_user'] ?? null;
}
