<?php
/**
 * ConnectMeet - Health / diagnostics endpoint.
 *
 *   GET /api/health.php
 * Returns DB connectivity, PHP version, meeting counts, and ICE config.
 * Used by install checks and monitoring. No credentials exposed.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$cmDbOk = false;
$cmErr = null;
$cmCounts = [];
if ($CM_PDO === null) {
    $cmErr = ($CM_DB_ERROR ?? 'Database connection failed.');
} else {
    try {
        $cmDbOk = (bool)$CM_PDO->query('SELECT 1')->fetch();
        $cmCounts = [
            'meetings'   => (int)$CM_PDO->query('SELECT COUNT(*) c FROM meetings')->fetch()['c'],
            'participants' => (int)$CM_PDO->query('SELECT COUNT(*) c FROM meeting_participants WHERE left_at IS NULL')->fetch()['c'],
            'users'      => (int)$CM_PDO->query('SELECT COUNT(*) c FROM users')->fetch()['c'],
        ];
    } catch (Throwable $e) {
        $cmErr = 'DB: ' . $e->getMessage();
        error_log('[ConnectMeet] health db error: ' . $e->getMessage());
    }
}

cm_ok([
    'app'          => $CM_CONFIG['app']['name'] ?? 'ConnectMeet',
    'php_version'  => PHP_VERSION,
    'db'           => $cmDbOk ? 'connected' : 'error',
    'db_error'     => $cmErr,
    'counts'       => $cmCounts,
    'timezone'     => date_default_timezone_get(),
    'ice_servers'  => cm_ice_config(),
    'polling_ms'   => cm_setting('polling_interval_ms', '2000'),
], 'ConnectMeet health check.');
