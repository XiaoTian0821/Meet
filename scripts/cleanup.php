<?php
/**
 * ConnectMeet — background cleanup.
 *
 * Intended to run from cron on shared/production hosts:
 *   * /scripts/cleanup.php
 * It prunes stale participants, expires overdue meetings, deletes old
 * transient signaling rows, and trims old app_settings / rate_limits.
 *
 * It is never exposed to the web directly: the .htaccess denies direct
 * access to /scripts/. A shared cron entry is roughly:
 *
 *     *  *  *  *  *  php /home/USER/public_html/connectmeet/scripts/cleanup.php
 */

declare(strict_types=1);
chdir(dirname(__DIR__));
require dirname(__DIR__) . '/includes/bootstrap.php';

// CLI guard: refuse to run under the web SAPI for the destructive ops,
// unless called from a cron/CLI context.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cli-server') {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "Direct web access to cleanup.php is not permitted.\n";
    exit;
}

$summary = [];

try {
    // 1. Prune stale participants across all active meetings.
    $staleSec = (int)cm_setting('stale_participant_sec', 30);
    $stmt = $CM_PDO->prepare(
        'UPDATE meeting_participants
         SET left_at = NOW()
         WHERE left_at IS NULL
           AND (last_seen_at IS NULL OR last_seen_at < (NOW() - INTERVAL ? SECOND))'
    );
    $stmt->execute([$staleSec]);
    $summary['stale_participants_marked_left'] = $stmt->rowCount();

    // 2. Mark expired meetings (active/scheduled past their expires_at).
    $upd = $CM_PDO->prepare(
        "UPDATE meetings SET status = 'expired'
         WHERE status IN ('active','scheduled','locked') AND expires_at IS NOT NULL AND expires_at < NOW()"
    );
    $upd->execute();
    $summary['meetings_expired'] = $upd->rowCount();

    // 3. End meetings that had no activity for a long time.
    $upd2 = $CM_PDO->prepare(
        "UPDATE meetings SET status = 'ended'
         WHERE status = 'active'
           AND updated_at < (NOW() - INTERVAL ? HOUR)"
    );
    $upd2->execute([(int)cm_setting('meeting_idle_end_hours', 24)]);
    $summary['meetings_ended_idle'] = $upd2->rowCount();

    // 4. Drop transient signaling rows that are delivered or old.
    $ageDays = 2;
    $del = $CM_PDO->prepare(
        'DELETE FROM meeting_events
         WHERE delivered_at IS NOT NULL
            OR created_at < (NOW() - INTERVAL ? DAY)'
    );
    $del->execute([$ageDays]);
    $summary['events_purged'] = $del->rowCount();

    // 5. Trim old chat beyond 30 days.
    $del2 = $CM_PDO->prepare('DELETE FROM meeting_messages WHERE created_at < (NOW() - INTERVAL 30 DAY)');
    $del2->execute();
    $summary['messages_purged'] = $del2->rowCount();

    // 6. Drop rate-limit windows older than an hour.
    $del3 = $CM_PDO->prepare('DELETE FROM rate_limits WHERE window_start < (NOW() - INTERVAL 1 HOUR)');
    $del3->execute();
    $summary['rate_limits_purged'] = $del3->rowCount();

} catch (Throwable $e) {
    $summary['error'] = $e->getMessage();
    error_log('[ConnectMeet cleanup] ' . $e->getMessage());
}

$out = '[ConnectMeet cleanup ' . date('c') . '] ' . json_encode($summary);
if (PHP_SAPI === 'cli') {
    echo $out . "\n";
}
error_log($out);
