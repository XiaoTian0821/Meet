<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$cmIce = cm_ice_config();
$cmSettings = [
    'polling_ms' => (int)cm_setting('polling_interval_ms', 2000),
    'stale_sec'  => (int)cm_setting('stale_participant_sec', 30),
    'max_name'   => (int)cm_setting('max_display_name_len', 40),
    'max_chat'   => (int)cm_setting('max_chat_len', 1000),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>ConnectMeet — Meeting</title>
<link rel="stylesheet" href="assets/css/style.css?v=<?= (int)filemtime(__DIR__ . '/assets/css/style.css') ?>">
<link rel="stylesheet" href="assets/css/responsive.css?v=<?= (int)filemtime(__DIR__ . '/assets/css/responsive.css') ?>">
<script>
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
<body class="page-room">
<div id="cmDbBanner" class="cm-db-banner" hidden></div>

<!-- ============ LOBBY (pre-join) ============ -->
<section id="cmLobby" class="cm-lobby" hidden>
    <div class="cm-lobby-head">
        <span class="cm-logo" aria-hidden="true"><svg viewBox="0 0 32 32" width="26" height="26"><circle cx="16" cy="16" r="14" fill="currentColor" opacity=".18"/><path d="M6 16a10 10 0 0 1 10-10v20A10 10 0 0 1 6 16Z" fill="currentColor"/></svg></span>
        <span class="cm-lobby-title" id="cmLobbyTitle">ConnectMeet</span>
    </div>

    <div class="cm-lobby-body">
        <div class="cm-preview-col">
            <div class="cm-preview" id="cmLobbyPreviewWrap">
                <video id="cmLobbyPreview" class="cm-video cm-video-local" autoplay muted playsinline></video>
                <div class="cm-preview-overlay">
                    <span class="cm-preview-hint">Camera off</span>
                </div>
                <img id="cmLobbyAvatar" class="cm-tile-avatar" alt="" hidden>
            </div>
            <div class="cm-mic-meter" aria-hidden="true">
                <div class="cm-mic-bar"><span id="cmMicMeterFill"></span></div>
                <span class="cm-mic-label" id="cmMicMeterLabel">Mic</span>
            </div>
        </div>

        <div class="cm-lobby-controls">
            <div class="cm-field">
                <label for="cmLobbyName">Your name</label>
                <input id="cmLobbyName" type="text" maxlength="60" placeholder="Display name">
            </div>

            <div class="cm-lowerbar" id="cmLobbyLower">
                <button class="cm-ctl" id="cmLobbyMic" aria-pressed="true" title="Mute microphone (A)"><span class="cm-ico" aria-hidden="true">🎙</span></button>
                <button class="cm-ctl" id="cmLobbyCam" aria-pressed="true" title="Turn camera on/off (V)"><span class="cm-ico" aria-hidden="true">📷</span></button>
                <button class="cm-ctl cm-ctl-share" id="cmLobbyShare" title="Present / share screen"><span class="cm-ico" aria-hidden="true">⬒</span></button>
                <button class="cm-ctl cm-ctl-settings" id="cmLobbySettings" title="Settings"><span class="cm-ico" aria-hidden="true">⚙</span></button>
                <button class="cm-btn cm-btn-primary cm-join-btn" id="cmLobbyJoin">Join now</button>
            </div>
        </div>
    </div>
</section>

<!-- ============ IN-MEETING ============ -->
<section id="cmMeeting" class="cm-meeting" hidden>
    <div class="cm-grid-wrap">
        <div id="cmGrid" class="cm-grid" role="list" aria-label="Participants"></div>

        <!-- Floating reactions layer -->
        <div id="cmReactionLayer" class="cm-reaction-layer" aria-hidden="true"></div>

        <!-- Active speaker / status ribbon -->
        <div id="cmStatusRibbon" class="cm-status-ribbon" hidden></div>
    </div>

    <!-- Right-side panels (chat / participants / details) -->
    <aside id="cmPanel" class="cm-panel" hidden>
        <div class="cm-panel-tabs">
            <button class="cm-tab active" data-panel="chat">Chat</button>
            <button class="cm-tab" data-panel="people">People</button>
            <button class="cm-tab" data-panel="details">Details</button>
            <button class="cm-tab-close" id="cmPanelClose" aria-label="Close panel">✕</button>
        </div>

        <div class="cm-panel-body" id="cmPanelChat">
            <div class="cm-chat-list" id="cmChatList" aria-live="polite"></div>
            <form class="cm-chat-input" id="cmChatForm">
                <label for="cmChatText" class="cm-sr-only">Message</label>
                <input id="cmChatText" type="text" autocomplete="off" placeholder="Send a message" maxlength="1000">
                <button class="cm-btn sm" type="submit">Send</button>
            </form>
        </div>

        <div class="cm-panel-body" id="cmPanelPeople" hidden>
            <ul class="cm-people-list" id="cmPeopleList"></ul>
        </div>

        <div class="cm-panel-body" id="cmPanelDetails" hidden>
            <div class="cm-details">
                <div class="cm-detail-row"><span class="cm-detail-k">Code</span><code id="cmDetailCode"></code></div>
                <div class="cm-detail-row"><span class="cm-detail-k">Link</span><span id="cmDetailLink"></span></div>
                <div class="cm-detail-row"><span class="cm-detail-k">Status</span><span id="cmDetailStatus">Connected</span></div>
                <button class="cm-btn sm" id="cmCopyLink">Copy meeting link</button>
            </div>
        </div>
    </aside>

    <!-- Captions -->
    <div id="cmCaptions" class="cm-captions" hidden aria-live="off"></div>

    <!-- Bottom control bar -->
    <footer class="cm-controls" id="cmControls">
        <button class="cm-ctl" id="cmCtrlMic" aria-pressed="true" title="Mute / unmute (A)"><span class="cm-ico" aria-hidden="true">🎙</span></button>
        <button class="cm-ctl" id="cmCtrlCam" aria-pressed="true" title="Camera on/off (V)"><span class="cm-ico" aria-hidden="true">📷</span></button>
        <button class="cm-ctl" id="cmCtrlShare" title="Share screen"><span class="cm-ico" aria-hidden="true">⬒</span></button>
        <button class="cm-ctl" id="cmCtrlRec" title="Record locally"><span class="cm-ico" aria-hidden="true">●</span></button>
        <button class="cm-ctl" id="cmCtrlReactions" title="React"><span class="cm-ico" aria-hidden="true">☺</span></button>
        <button class="cm-ctl" id="cmCtrlHand" aria-pressed="false" title="Raise / lower hand (H)"><span class="cm-ico" aria-hidden="true">✋</span></button>
        <button class="cm-ctl" id="cmCtrlCaptions" aria-pressed="false" title="Captions (T)"><span class="cm-ico" aria-hidden="true">CC</span></button>
        <button class="cm-ctl" id="cmCtrlPanel" title="Chat &amp; people"><span class="cm-ico" aria-hidden="true">💬</span></button>
        <button class="cm-ctl" id="cmCtrlSettings" title="Settings"><span class="cm-ico" aria-hidden="true">⚙</span></button>
        <button class="cm-ctl cm-ctl-leave" id="cmCtrlLeave" title="Leave meeting"><span class="cm-ico" aria-hidden="true">📞</span></button>
    </footer>

    <!-- Reaction picker -->
    <div id="cmReactionPicker" class="cm-reaction-picker" hidden>
        <button data-emoji="👍">👍</button>
        <button data-emoji="❤️">❤️</button>
        <button data-emoji="😂">😂</button>
        <button data-emoji="🎉">🎉</button>
        <button data-emoji="😮">😮</button>
        <button data-emoji="👏">👏</button>
    </div>

    <!-- Recording indicator -->
    <div id="cmRecIndicator" class="cm-rec-indicator" hidden>
        <span class="cm-rec-dot"></span> Recording <span id="cmRecTime">00:00</span>
    </div>

    <!-- Connection status pill -->
    <div id="cmConnPill" class="cm-conn-pill" title="Connection status">Connected</div>

    <div id="cmMeetingToasts" class="cm-toasts cm-toasts-meeting" aria-live="polite"></div>
</section>

<!-- ============ SETTINGS MODAL ============ -->
<div id="cmSettingsModal" class="cm-modal" hidden>
    <div class="cm-modal-card" role="dialog" aria-modal="true" aria-labelledby="cmSettingsTitle">
        <h2 id="cmSettingsTitle">Settings</h2>
        <div class="cm-settings-grid">
            <div class="cm-field">
                <label for="cmSetCam">Camera</label>
                <select id="cmSetCam" class="cm-select"></select>
            </div>
            <div class="cm-field">
                <label for="cmSetMic">Microphone</label>
                <select id="cmSetMic" class="cm-select"></select>
            </div>
            <div class="cm-field">
                <label for="cmSetSpk">Audio output</label>
                <select id="cmSetSpk" class="cm-select"><option value="">Default output</option></select>
                <p class="cm-fineprint">Speaker switching may be unavailable on this browser.</p>
            </div>
            <div class="cm-field">
                <label class="cm-check"><input id="cmSetCaptions" type="checkbox"><span>Enable live captions (where supported)</span></label>
                <label class="cm-check"><input id="cmSetSaveName" type="checkbox" checked><span>Remember my display name</span></label>
            </div>
        </div>
        <div class="cm-mic-meter"><div class="cm-mic-bar"><span id="cmSetMicMeter"></span></div><span class="cm-mic-label">Mic level</span></div>
        <div class="cm-modal-actions">
            <button class="cm-btn" id="cmSpeakerTest" type="button">Speaker test</button>
            <button class="cm-btn cm-btn-primary" data-close type="button">Done</button>
        </div>
    </div>
</div>

<!-- End-for-everyone confirm -->
<div id="cmEndModal" class="cm-modal" hidden>
    <div class="cm-modal-card" role="dialog" aria-modal="true" aria-labelledby="cmEndTitle">
        <h2 id="cmEndTitle">End meeting for everyone?</h2>
        <p class="cm-fineprint">All participants will be disconnected. Only the host can do this.</p>
        <div class="cm-modal-actions">
            <button class="cm-btn" data-close type="button">Cancel</button>
            <button class="cm-btn cm-btn-danger" id="cmEndConfirm" type="button">End for everyone</button>
        </div>
    </div>
</div>

<script src="assets/js/webrtc.js?v=<?= (int)filemtime(__DIR__ . '/assets/js/webrtc.js') ?>"></script>
<script src="assets/js/signaling.js?v=<?= (int)filemtime(__DIR__ . '/assets/js/signaling.js') ?>"></script>
<script src="assets/js/settings.js?v=<?= (int)filemtime(__DIR__ . '/assets/js/settings.js') ?>"></script>
<script src="assets/js/meeting.js?v=<?= (int)filemtime(__DIR__ . '/assets/js/meeting.js') ?>"></script>
</body>
</html>
