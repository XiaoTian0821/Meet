<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$cmIce = cm_ice_config();
$cmSettings = [
    'polling_ms' => (int)cm_setting('polling_interval_ms', 2000),
    'stale_sec'  => (int)cm_setting('stale_participant_sec', 30),
    'max_name'   => (int)cm_setting('max_display_name_len', 40),
    'max_chat'   => (int)cm_setting('max_chat_len', 1000),
    'max_limit'  => (int)($CM_CONFIG['app']['meeting']['max_participant_limit'] ?? 12),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>ConnectMeet — Video Conferencing</title>
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/responsive.css">
<script>
// Expose non-sensitive runtime config + ICE servers to the JS modules.
window.CM_CONFIG = {
    baseUrl: <?= json_encode(cm_url()) ?>,
    iceServers: <?= json_encode($cmIce, JSON_UNESCAPED_SLASHES) ?>,
    settings: <?= json_encode($cmSettings, JSON_UNESCAPED_SLASHES) ?>,
    dbAvailable: <?= json_encode(($CM_PDO !== null)) ?>,
    dbError: <?= json_encode($CM_DB_ERROR ?? null) ?>,
    now: <?= json_encode(date('Y-m-d\TH:i:sP')) ?>
};
</script>
</head>
<body class="page-landing">
<div id="cmDbBanner" class="cm-db-banner" hidden></div>

<header class="cm-topbar">
    <div class="cm-brand">
        <span class="cm-logo" aria-hidden="true">
            <svg viewBox="0 0 32 32" width="30" height="30"><circle cx="16" cy="16" r="14" fill="currentColor" opacity=".18"/><path d="M6 16a10 10 0 0 1 10-10v20A10 10 0 0 1 6 16Z" fill="currentColor"/><path d="M16 6a10 10 0 0 1 0 20V6Z" fill="currentColor" opacity=".5"/></svg>
        </span>
        <span class="cm-brand-name">ConnectMeet</span>
    </div>
    <div class="cm-topbar-right">
        <span id="cmClock" class="cm-clock" aria-live="off">--:--</span>
        <button id="cmHelpBtn" class="cm-ghost-btn" type="button" aria-haspopup="true" aria-expanded="false" title="Help &amp; shortcuts">
            <span aria-hidden="true">?</span> Help
        </button>
    </div>
</header>

<main class="cm-hero">
    <section class="cm-hero-card" aria-labelledby="cmHeroTitle">
        <h1 id="cmHeroTitle" class="cm-hero-title">New meeting</h1>
        <p class="cm-hero-sub">Instant video meetings. No download, no install — just a link.</p>

        <button id="cmStartInstant" class="cm-btn cm-btn-primary cm-start-row" type="button">
            <span class="cm-ico" aria-hidden="true">▶</span>
            <span class="cm-start-txt">Start instant meeting</span>
        </button>

        <div class="cm-actions">
            <button id="cmCreateLater" class="cm-btn" type="button">Create meeting for later</button>
            <div class="cm-join">
                <label for="cmJoinCode" class="cm-sr-only">Meeting code</label>
                <input id="cmJoinCode" type="text" autocomplete="off" spellcheck="false"
                       placeholder="Enter meeting code or link" aria-describedby="cmJoinErr">
                <button id="cmJoinBtn" class="cm-btn cm-btn-primary" type="button">Join</button>
            </div>
        </div>

        <div id="cmJoinErr" class="cm-field-error" role="alert" hidden></div>
    </section>

    <aside class="cm-hero-side" aria-label="Meeting options">
        <h2 class="cm-side-title">Options</h2>
        <div class="cm-field">
            <label for="cmDisplayName">Your name</label>
            <input id="cmDisplayName" type="text" maxlength="60" placeholder="How others will see you" value="">
        </div>
        <div class="cm-device-row">
            <label class="cm-dev-label">Camera</label>
            <select id="cmSelectCam" class="cm-select"><option value="">Default</option></select>
        </div>
        <div class="cm-device-row">
            <label class="cm-dev-label">Microphone</label>
            <select id="cmSelectMic" class="cm-select"><option value="">Default</option></select>
        </div>
        <div class="cm-check-row">
            <label class="cm-check"><input id="cmCamOn" type="checkbox" checked><span>Turn on camera</span></label>
            <label class="cm-check"><input id="cmMicOn" type="checkbox" checked><span>Turn on microphone</span></label>
        </div>
        <p class="cm-fineprint">Your name and device choices are remembered on this device. Camera and microphone stay local until you join.</p>
    </aside>
</main>

<!-- Notifications -->
<div id="cmToasts" class="cm-toasts" aria-live="polite" aria-atomic="false"></div>

<!-- Help / shortcuts popup -->
<div id="cmHelpPop" class="cm-popover" hidden>
    <h3>Keyboard shortcuts</h3>
    <dl class="cm-shortcuts">
        <dt><kbd>A</kbd></dt><dd>Turn mic on / off</dd>
        <dt><kbd>V</kbd></dt><dd>Turn camera on / off</dd>
        <dt><kbd>H</kbd></dt><dd>Raise / lower hand</dd>
        <dt><kbd>T</kbd></dt><dd>Toggle captions</dd>
        <dt><kbd>⎋</kbd></dt><dd>Close panels / dialogs</dd>
    </dl>
    <p class="cm-fineprint">Media requires HTTPS (or localhost). For remote access over a plain HTTP network, camera/mic will be blocked by the browser.</p>
</div>

<!-- "Create for later" modal -->
<div id="cmLaterModal" class="cm-modal" hidden>
    <div class="cm-modal-card" role="dialog" aria-modal="true" aria-labelledby="cmLaterTitle">
        <h2 id="cmLaterTitle">Create meeting for later</h2>
        <div class="cm-field">
            <label for="cmLaterTitleInput">Title</label>
            <input id="cmLaterTitleInput" type="text" maxlength="128" placeholder="Weekly sync">
        </div>
        <div class="cm-field">
            <label for="cmLaterTime">Start at (optional)</label>
            <input id="cmLaterTime" type="datetime-local">
            <p class="cm-fineprint">Leave blank for an instant meeting that starts now.</p>
        </div>
        <div class="cm-field">
            <label for="cmLaterLimit">Max participants</label>
            <input id="cmLaterLimit" type="number" min="2" value="8">
            <p class="cm-fineprint">Mesh prototype supports up to <?php echo (int)($CM_CONFIG['app']['meeting']['max_participant_limit'] ?? 12); ?> participants.</p>
        </div>
        <div class="cm-modal-actions">
            <button class="cm-btn" data-close type="button">Cancel</button>
            <button class="cm-btn cm-btn-primary" id="cmLaterCreate" type="button">Create</button>
        </div>
    </div>
</div>

<!-- Success modal (after creating a meeting) -->
<div id="cmCreatedModal" class="cm-modal" hidden>
    <div class="cm-modal-card" role="dialog" aria-modal="true" aria-labelledby="cmCreatedTitle">
        <h2 id="cmCreatedTitle">Meeting ready</h2>
        <p class="cm-fineprint">Share this link or code. Keep the code secret — anyone with it can join.</p>
        <div class="cm-codebox">
            <code id="cmCreatedCode" class="cm-code"></code>
            <button class="cm-btn sm" id="cmCopyCode" type="button">Copy code</button>
        </div>
        <div class="cm-codebox">
            <code id="cmCreatedLink" class="cm-code cm-link"></code>
            <button class="cm-btn sm" id="cmCopyLink" type="button">Copy link</button>
        </div>
        <div class="cm-modal-actions">
            <button class="cm-btn" data-close type="button">Close</button>
            <button class="cm-btn cm-btn-primary" id="cmJoinCreated" type="button">Join this meeting</button>
        </div>
    </div>
</div>

<script src="assets/js/app.js"></script>
</body>
</html>
