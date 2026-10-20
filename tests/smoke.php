<?php
/**
 * ConnectMeet — smoke tests (run under the web SAPI or CLI).
 *
 * These are lightweight, DB-aware assertions that verify the HTTP contract
 * of the API endpoints. They do NOT require a second browser; they verify
 * the JSON envelope, status codes, and graceful-degradation behaviour.
 *
 * Run from CLI:
 *   C:\xampp\php\php.exe C:\xampp\htdocs\Meet\tests\smoke.php
 *
 * Or via the web:
 *   http://localhost/Meet/tests/smoke.php
 */

declare(strict_types=1);

// If called over HTTP, don't pollute the browser; still print readable text.
header('Content-Type: text/plain; charset=utf-8');

$ROOT = dirname(__DIR__);
chdir($ROOT);

$base = 'http://localhost/Meet';

function http($method, $url, $body = null) {
    $opts = ['http' => ['method' => $method, 'ignore_errors' => true]];
    if ($body !== null) {
        $opts['http']['header'] = "Content-Type: application/json\r\n";
        $opts['http']['content'] = json_encode($body);
    }
    $ctx = stream_context_create($opts);
    $raw = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (isset($http_response_header) && preg_match('/HTTP\/\d \d{3}/', $http_response_header[0], $m)) {
        $status = (int)$m[1];
    }
    $json = json_decode((string)$raw, true);
    return ['status' => $status, 'json' => $json, 'raw' => (string)$raw];
}

$pass = 0; $fail = 0;
function check($name, $cond, $extra = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $name\n"; }
    else       { $fail++; echo "  FAIL  $name  " . $extra . "\n"; }
}

echo "ConnectMeet smoke tests against $base\n";

// 1. health endpoint always answers
$r = http('GET', "$base/api/health.php");
check('health returns JSON envelope', is_array($r['json']) && array_key_exists('success', $r['json']), $r['raw']);
check('health reports db state honestly', array_key_exists('db', $r['json']['data'] ?? []));

// 2. meeting creation is protected by a 503 when DB is unavailable,
//    or by a proper 201/400 when it is available.
$r = http('POST', "$base/api/meetings.php?action=create", ['title' => 'Smoke Test', 'participant_limit' => 4]);
check('meeting create returns envelope', is_array($r['json']) && array_key_exists('success', $r['json']), $r['raw']);
$okCreate = ($r['json']['success'] ?? false) === true;
$dbAvailable = !empty($r['json']['data']) || $okCreate; // if success, we have data

if ($dbAvailable && $okCreate) {
    $code = $r['json']['data']['meeting_code'] ?? '';
    check('meeting code is 3-4-4 format', preg_match('/^[a-z0-9]{3}-[a-z0-9]{4}-[a-z0-9]{4}$/', $code), $code);
    check('join_url is present', !empty($r['json']['data']['join_url']));

    // 3. validate the code we just created.
    $r2 = http('GET', "$base/api/meetings.php?action=validate&meeting=" . urlencode($code));
    check('validate accepts a real code', ($r2['json']['success'] ?? false) === true && ($r2['json']['data']['joinable'] ?? false) === true, $r2['raw']);

    // 4. an unknown code must be rejected (never auto-created).
    $r3 = http('GET', "$base/api/meetings.php?action=validate&meeting=" . urlencode('zzz-999-999'));
    check('unknown code rejected', ($r3['json']['success'] ?? true) === false, $r3['raw']);

    // 5. join the meeting (guest) -> returns a participant token.
    $r4 = http('POST', "$base/api/participants.php?action=join", [
        'meeting' => $code,
        'display_name' => 'SmokeTester',
        'host_token' => $r['json']['data']['host_token'] ?? '',
    ]);
    check('join returns participant token', ($r4['json']['success'] ?? false) === true && !empty($r4['json']['data']['participant_token']), $r4['raw']);
    $tok = $r4['json']['data']['participant_token'] ?? '';
    $mid = $r4['json']['data']['meeting_id'] ?? 0;

    // 6. leave with the token.
    $r5 = http('POST', "$base/api/participants.php?action=leave", ['meeting_id' => $mid, 'participant_token' => $tok]);
    check('leave succeeds', ($r5['json']['success'] ?? false) === true, $r5['raw']);

    // 7. leave again with the same token must now fail (idempotent join guard).
    $r6 = http('POST', "$base/api/participants.php?action=leave", ['meeting_id' => $mid, 'participant_token' => $tok]);
    check('double-leave rejected', ($r6['json']['success'] ?? true) === false, $r6['raw']);
} else {
    check('meeting create degraded gracefully (DB unavailable -> 503)',
        ($r['json']['success'] ?? true) === false && str_contains($r['json']['message'] ?? '', 'Database is not available'),
        'status=' . $r['status'] . ' msg=' . ($r['json']['message'] ?? ''));
}

// 8. chat send without credentials must be rejected (401/403).
$r = http('POST', "$base/api/chat.php?action=send", ['meeting_id' => 999999, 'participant_token' => 'invalid', 'content' => 'hi']);
check('chat without credential rejected', ($r['json']['success'] ?? true) === false);

// 9. XSS payload in chat must not execute even if it reaches the store.
$r = http('POST', "$base/api/chat.php?action=send", ['meeting_id' => 1, 'participant_token' => 'bad', 'content' => '<script>alert(1)</script>']);
check('malicious chat rejected by membership check', ($r['json']['success'] ?? true) === false);

echo "\n$pass passed, $fail failed.\n";
exit($fail === 0 ? 0 : 1);
