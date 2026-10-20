/* ConnectMeet — landing page logic
 * Handles: clock, join/create meetings, device selection,
 * "create for later", copy-to-clipboard, toasts, help popover.
 * All API calls go to /api/*.php over JSON.
 */
(function () {
  'use strict';

  var CFG = window.CM_CONFIG || {};
  var API = function (p) { return (CFG.baseUrl || '') + '/' + p; };

  // ---------- localStorage prefs (non-sensitive) ----------
  function getPref(key, dflt) { try { return localStorage.getItem(key) ?? dflt; } catch (e) { return dflt; } }
  function setPref(key, val) { try { localStorage.setItem(key, val); } catch (e) {} }

  // ---------- toasts ----------
  var toastWrap = document.getElementById('cmToasts');
  function toast(msg, kind) {
    var el = document.createElement('div');
    el.className = 'cm-toast' + (kind ? ' ' + kind : '');
    el.textContent = msg;
    toastWrap.appendChild(el);
    setTimeout(function () { el.remove(); }, 4000);
  }

  // ---------- clock ----------
  var clock = document.getElementById('cmClock');
  function tick() {
    var d = new Date();
    clock.textContent = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) +
      ' ' + d.toLocaleDateString([], { day: '2-digit', month: 'short', year: 'numeric' });
  }
  tick();
  setInterval(tick, 30000);

  // ---------- database availability banner ----------
  (function () {
    if (CFG.dbAvailable === false) {
      var b = document.getElementById('cmDbBanner');
      if (b) {
        b.textContent = 'Database is not connected — meeting features are disabled until you import database/install.sql and configure config/database.php. ' + (CFG.dbError || '');
        b.removeAttribute('hidden');
      }
    }
  })();

  // ---------- device enumeration ----------
  var selCam = document.getElementById('cmSelectCam');
  var selMic = document.getElementById('cmSelectMic');
  function populateDevices() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) return;
    navigator.mediaDevices.enumerateDevices().then(function (list) {
      var cams = list.filter(function (d) { return d.kind === 'videoinput'; });
      var mics = list.filter(function (d) { return d.kind === 'audioinput'; });
      fillSelect(selCam, cams, 'Camera');
      fillSelect(selMic, mics, 'Microphone');
    }).catch(function () {});
  }
  function fillSelect(sel, items, prefix) {
    sel.innerHTML = '';
    var def = document.createElement('option');
    def.value = '';
    def.textContent = prefix + ' (default)';
    sel.appendChild(def);
    items.forEach(function (d, i) {
      var o = document.createElement('option');
      o.value = d.deviceId;
      o.textContent = d.label || (prefix + ' ' + (i + 1));
      sel.appendChild(o);
    });
  }
  populateDevices();

  // Restore saved name.
  document.getElementById('cmDisplayName').value = getPref('cm_name', '');

  // Re-enumerate once after the user grants permission (labels are empty until then).
  navigator.mediaDevices && navigator.mediaDevices.enumerateDevices &&
    navigator.mediaDevices.addEventListener('devicechange', populateDevices);

  // ---------- help popover ----------
  var helpBtn = document.getElementById('cmHelpBtn');
  var helpPop = document.getElementById('cmHelpPop');
  helpBtn.addEventListener('click', function () {
    var open = helpPop.hasAttribute('hidden');
    if (open) { helpPop.removeAttribute('hidden'); helpBtn.setAttribute('aria-expanded', 'true'); }
    else { helpPop.setAttribute('hidden', ''); helpBtn.setAttribute('aria-expanded', 'false'); }
  });
  document.addEventListener('click', function (e) {
    if (!helpPop.hasAttribute('hidden') && !helpPop.contains(e.target) && e.target !== helpBtn) {
      helpPop.setAttribute('hidden', '');
      helpBtn.setAttribute('aria-expanded', 'false');
    }
  });

  // ---------- modal helpers ----------
  function openModal(id) { document.getElementById(id).removeAttribute('hidden'); }
  function closeModal(id) { document.getElementById(id).setAttribute('hidden', ''); }
  document.querySelectorAll('.cm-modal [data-close]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var m = btn.closest('.cm-modal');
      if (m) m.setAttribute('hidden', '');
    });
  });
  document.querySelectorAll('.cm-modal').forEach(function (m) {
    m.addEventListener('click', function (e) { if (e.target === m) m.setAttribute('hidden', ''); });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('.cm-modal:not([hidden])').forEach(function (m) { m.setAttribute('hidden', ''); });
      helpPop.setAttribute('hidden', '');
    }
  });

  // ---------- JSON fetch helper ----------
  function postJSON(url, body) {
    var opts = { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' } };
    if (body) opts.body = JSON.stringify(body);
    return fetch(API(url), opts).then(function (r) { return r.json(); });
  }
  function getJSON(url) {
    return fetch(API(url), { headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); });
  }

  // Determine the meeting code from a pasted link or bare code.
  function extractCode(input) {
    var s = input.trim();
    if (!s) return '';
    try {
      var u = new URL(s);
      var q = u.searchParams.get('meeting');
      if (q) return q;
    } catch (e) { /* not a URL */ }
    return s;
  }

  // ---------- Join ----------
  var joinBtn = document.getElementById('cmJoinBtn');
  var joinInput = document.getElementById('cmJoinCode');
  var joinErr = document.getElementById('cmJoinErr');

  function doJoin() {
    var code = extractCode(joinInput.value);
    if (!code) { showErr('Enter a meeting code or paste a link.'); return; }
    joinErr.hidden = true;
    joinBtn.disabled = true;
    postJSON('api/participants.php?action=join', {
      meeting: code,
      display_name: document.getElementById('cmDisplayName').value.trim(),
      audio_enabled: document.getElementById('cmMicOn').checked ? 1 : 0,
      video_enabled: document.getElementById('cmCamOn').checked ? 1 : 0
    }).then(function (res) {
      joinBtn.disabled = false;
      if (!res.success) { showErr(res.message || 'Could not join.'); return; }
      // Persist prefs.
      setPref('cm_name', document.getElementById('cmDisplayName').value.trim());
      setPref('cm_cam', document.getElementById('cmCamOn').checked ? '1' : '0');
      setPref('cm_mic', document.getElementById('cmMicOn').checked ? '1' : '0');
      // Hand off to the room page via session + query.
      sessionStorage.setItem('cm_join', JSON.stringify(res.data));
      location.href = API('room.php?meeting=' + encodeURIComponent(res.data.meeting_code));
    }).catch(function () {
      joinBtn.disabled = false;
      showErr('Network error. Please try again.');
    });
  }
  function showErr(msg) { joinErr.textContent = msg; joinErr.hidden = false; }

  joinBtn.addEventListener('click', doJoin);
  joinInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') doJoin(); });

  // Pre-fill join code from ?meeting=
  var qMeeting = new URLSearchParams(location.search).get('meeting');
  if (qMeeting) joinInput.value = qMeeting;

  // ---------- Start instant meeting ----------
  document.getElementById('cmStartInstant').addEventListener('click', function () {
    createMeeting({ instant: true });
  });

  // ---------- Create for later ----------
  var laterModal = document.getElementById('cmLaterModal');
  document.getElementById('cmCreateLater').addEventListener('click', function () {
    var now = new Date(Date.now() + 60000); // min 1 min ahead
    var local = now.toISOString().slice(0, 16);
    document.getElementById('cmLaterTime').value = local;
    document.getElementById('cmLaterLimit').value = '8';
    openModal('cmLaterModal');
  });
  document.getElementById('cmLaterCreate').addEventListener('click', function () {
    var title = document.getElementById('cmLaterTitleInput').value.trim();
    if (!title) { toast('Please give the meeting a title.', 'warn'); return; }
    var sched = document.getElementById('cmLaterTime').value;
    var limit = document.getElementById('cmLaterLimit').value;
    createMeeting({
      title: title,
      scheduled_at: sched ? sched : null,
      participant_limit: limit,
      instant: false
    });
  });

  function createMeeting(opts) {
    var body = {
      title: opts.title || ('Meeting ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })),
      participant_limit: opts.participant_limit || 8,
      scheduled_at: opts.scheduled_at || null
    };
    postJSON('api/meetings.php?action=create', body).then(function (res) {
      if (!res.success) { toast(res.message || 'Could not create the meeting.', 'err'); return; }
      var d = res.data;
      // Remember host token so this browser can act as host.
      sessionStorage.setItem('cm_host_token_' + d.meeting_code, d.host_token);
      document.getElementById('cmCreatedCode').textContent = d.meeting_code;
      document.getElementById('cmCreatedLink').textContent = d.join_url;
      sessionStorage.setItem('cm_created', JSON.stringify(d));
      openModal('cmCreatedModal');
      closeModal('cmLaterModal');
    }).catch(function () { toast('Network error creating the meeting.', 'err'); });
  }

  // ---------- Copy buttons ----------
  function copyText(text, btn) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () { flashCopied(btn); })
        .catch(function () { legacyCopy(text, btn); });
    } else legacyCopy(text, btn);
  }
  function legacyCopy(text, btn) {
    var ta = document.createElement('textarea');
    ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); flashCopied(btn); } catch (e) { toast('Copy failed.', 'err'); }
    ta.remove();
  }
  function flashCopied(btn) {
    var orig = btn.textContent;
    btn.textContent = 'Copied';
    setTimeout(function () { btn.textContent = orig; }, 1500);
  }
  document.getElementById('cmCopyCode').addEventListener('click', function () {
    copyText(document.getElementById('cmCreatedCode').textContent, this);
  });
  document.getElementById('cmCopyLink').addEventListener('click', function () {
    copyText(document.getElementById('cmCreatedLink').textContent, this);
  });

  // ---------- Join the just-created meeting ----------
  document.getElementById('cmJoinCreated').addEventListener('click', function () {
    var d = JSON.parse(sessionStorage.getItem('cm_created') || 'null');
    if (!d) return;
    joinInput.value = d.meeting_code;
    closeModal('cmCreatedModal');
    // Auto-join: fetch join credentials.
    var hostTok = sessionStorage.getItem('cm_host_token_' + d.meeting_code) || '';
    postJSON('api/participants.php?action=join', {
      meeting: d.meeting_code,
      display_name: document.getElementById('cmDisplayName').value.trim() || 'Host',
      host_token: hostTok,
      audio_enabled: document.getElementById('cmMicOn').checked ? 1 : 0,
      video_enabled: document.getElementById('cmCamOn').checked ? 1 : 0
    }).then(function (res) {
      if (!res.success) { showErr(res.message || 'Could not join.'); return; }
      sessionStorage.setItem('cm_join', JSON.stringify(res.data));
      location.href = API('room.php?meeting=' + encodeURIComponent(d.meeting_code));
    }).catch(function () { showErr('Network error.'); });
  });

})();
