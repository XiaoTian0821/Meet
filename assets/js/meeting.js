/* ConnectMeet — meeting UI controller.
 *
 * Orchestrates: lobby -> in-meeting, video grid, chat, people panel,
 * reactions, hand-raise, captions, recording, settings modal, connection
 * status. Wires CMRTC (media) + CMSIG (signaling) to the DOM.
 */
(function (global) {
  'use strict';

  var CFG = global.CM_CONFIG || {};
  var RTC = global.CMRTC;
  var SIG = global.CMSIG;

  var el = {
    lobby: document.getElementById('cmLobby'),
    lobbyTitle: document.getElementById('cmLobbyTitle'),
    meeting: document.getElementById('cmMeeting'),
    grid: document.getElementById('cmGrid'),
    statusRibbon: document.getElementById('cmStatusRibbon'),
    connPill: document.getElementById('cmConnPill'),
    reactionLayer: document.getElementById('cmReactionLayer'),
    reactionPicker: document.getElementById('cmReactionPicker'),
    panel: document.getElementById('cmPanel'),
    panelClose: document.getElementById('cmPanelClose'),
    chatList: document.getElementById('cmChatList'),
    chatForm: document.getElementById('cmChatForm'),
    chatText: document.getElementById('cmChatText'),
    peopleList: document.getElementById('cmPeopleList'),
    detailCode: document.getElementById('cmDetailCode'),
    detailLink: document.getElementById('cmDetailLink'),
    detailStatus: document.getElementById('cmDetailStatus'),
    captions: document.getElementById('cmCaptions'),
    recIndicator: document.getElementById('cmRecIndicator'),
    recTime: document.getElementById('cmRecTime'),
    toasts: document.getElementById('cmMeetingToasts'),
    lobbyMic: document.getElementById('cmLobbyMic'),
    lobbyCam: document.getElementById('cmLobbyCam'),
    lobbyShare: document.getElementById('cmLobbyShare'),
    lobbySettings: document.getElementById('cmLobbySettings'),
    lobbyJoin: document.getElementById('cmLobbyJoin'),
    lobbyName: document.getElementById('cmLobbyName'),
    lobbyPreview: document.getElementById('cmLobbyPreview'),
    lobbyPreviewWrap: document.getElementById('cmLobbyPreviewWrap'),
    lobbyAvatar: document.getElementById('cmLobbyAvatar'),
    micMeterFill: document.getElementById('cmMicMeterFill'),
    ctrlMic: document.getElementById('cmCtrlMic'),
    ctrlCam: document.getElementById('cmCtrlCam'),
    ctrlShare: document.getElementById('cmCtrlShare'),
    ctrlRec: document.getElementById('cmCtrlRec'),
    ctrlReactions: document.getElementById('cmCtrlReactions'),
    ctrlHand: document.getElementById('cmCtrlHand'),
    ctrlCaptions: document.getElementById('cmCtrlCaptions'),
    ctrlPanel: document.getElementById('cmCtrlPanel'),
    ctrlSettings: document.getElementById('cmCtrlSettings'),
    ctrlLeave: document.getElementById('cmCtrlLeave'),
    copyLink: document.getElementById('cmCopyLink'),
    endModal: document.getElementById('cmEndModal'),
    endConfirm: document.getElementById('cmEndConfirm')
  };

  var meetingInfo = null;   // from /join
  var localTile = null;
  var recording = null;
  var recChunks = [];
  var recSeconds = 0;
  var captionsEnabled = false;
  var speechRec = null;
  var activeSpeakerId = null;
  var participantCache = [];
  var myId = null;

  // ---------- boot ----------
  function boot() {
    var join = null;
    try { join = JSON.parse(sessionStorage.getItem('cm_join') || 'null'); } catch (e) {}
    var qMeeting = new URLSearchParams(location.search).get('meeting');
    if (!join && !qMeeting) {
      // No credentials; bounce back to landing.
      location.replace((CFG.baseUrl || '') + '/index.php');
      return;
    }

    if (join) {
      meetingInfo = join;
      myId = join.participant_id;
      SIG.hostToken = sessionStorage.getItem('cm_host_token_' + join.meeting_code) || null;
      // Persist host token into SIG for host actions.
      if (SIG.hostToken) join.host_token = SIG.hostToken;
      enterMeeting();
    } else {
      // Arriving with just a ?meeting code: do a fresh join.
      fetchJoin(qMeeting).then(function (info) {
        meetingInfo = info;
        myId = info.participant_id;
        SIG.hostToken = sessionStorage.getItem('cm_host_token_' + info.meeting_code) || null;
        if (SIG.hostToken) info.host_token = SIG.hostToken;
        enterMeeting();
      }).catch(function (e) {
        toast('Could not join: ' + (e && e.message || 'network error'), 'err');
        setTimeout(function () { location.replace((CFG.baseUrl || '') + '/index.php'); }, 2500);
      });
    }
  }

  function fetchJoin(code) {
    var hostTok = sessionStorage.getItem('cm_host_token_' + code) || '';
    var name = localStorage.getItem('cm_name') || 'Guest';
    return fetch((CFG.baseUrl || '') + '/api/participants.php?action=join', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ meeting: code, display_name: name, host_token: hostTok })
    }).then(function (r) { return r.json(); }).then(function (res) {
      if (!res.success) throw new Error(res.message || 'join failed');
      return res.data;
    });
  }

  function enterMeeting() {
    // Show lobby, hide meeting.
    el.lobby.hidden = false;
    el.meeting.hidden = true;
    el.lobbyTitle.textContent = meetingInfo.title || 'Meeting';

    // Restore name.
    el.lobbyName.value = meetingInfo.display_name || localStorage.getItem('cm_name') || 'Guest';

    // Initialize WebRTC with current toggle state.
    RTC.init({
      myId: myId,
      micOn: meetingInfo.audio_enabled ? true : false,
      camOn: meetingInfo.video_enabled ? true : false,
      iceServers: meetingInfo.ice_servers || CFG.iceServers,
      onSignalSend: function (kind, to, payload) { return SIG.sendSignal(kind, to, payload); },
      onRemoteStream: function (pid, stream, name) { onRemoteStream(pid, stream, name); },
      onPeerGone: function (pid) { onRemoteGone(pid); },
      onPeerState: function (pid, state) { onPeerState(pid, state); },
      onActiveSpeaker: function (pid) { onActiveSpeaker(pid); },
      onShareState: function (sharing) { onShareState(sharing); },
      onLocalState: function (s) {
        syncLocalToggles(s);
        if (!el.lobby.hidden) {
          el.lobbyPreview.srcObject = RTC.localStream;
          el.lobbyPreviewWrap.classList.toggle('cam-off', !RTC.camOn || !RTC.localStream || !RTC.localStream.getVideoTracks().length);
          el.lobbyAvatar.hidden = !el.lobbyPreviewWrap.classList.contains('cam-off');
        }
      },
      onLocalLevel: function (lvl) { onLocalLevel(lvl); },
      onMediaError: function (message) {
        updateLobbyMediaUI();
        toast(message, 'err');
      }
    });

    // Acquire local media.
    RTC.acquireLocal().then(function (stream) {
      el.lobbyPreview.srcObject = stream;
      updateLobbyMediaUI();
    });

    // Start signaling.
    SIG.start(meetingInfo, {
      onEvents: function () {},
      onParticipantUpdates: function (parts, meta) { onParticipantUpdates(parts, meta); },
      onMeetingEnded: function (reason) { onMeetingEnded(reason); }
    });
    SIG.onChatMessages = onChatMessages;

    // Pre-create peer connections for participants already in the room.
    if (meetingInfo.participants) {
      meetingInfo.participants.forEach(function (p) {
        if (String(p.id) !== String(myId)) RTC.ensurePeer(p.id, p.display_name);
      });
    } else {
      // No embedded participant list: fetch current state once to discover peers.
      fetch((CFG.baseUrl || '') + '/api/participants.php?action=list&meeting_id=' +
        encodeURIComponent(meetingInfo.meeting_id) + '&participant_token=' +
        encodeURIComponent(meetingInfo.participant_token || ''), {
        headers: { 'Accept': 'application/json' }
      }).then(function (r) { return r.json(); }).then(function (res) {
        if (res.success && res.data) {
          meetingInfo.participants = res.data.participants;
          onParticipantUpdates(res.data.participants, res.data);
          res.data.participants.forEach(function (p) {
            if (String(p.id) !== String(myId)) RTC.ensurePeer(p.id, p.display_name);
          });
        }
      }).catch(function () {});
    }
  }

  // ---------- lobby controls ----------
  el.lobbyMic.addEventListener('click', function () { toggleMic(RTC.toggleMic(RTC.micOn ? false : true)); });
  el.lobbyCam.addEventListener('click', function () { toggleCam(RTC.toggleCam(RTC.camOn ? false : true)); });
  el.lobbyShare.addEventListener('click', function () {
    RTC.startShare().then(function () {
      SIG.sendEvent('screen_share', {});
      toast('Sharing your screen.', 'ok');
    }).catch(function (e) { toast(e.message || 'Screen sharing unavailable.', 'err'); });
  });
  el.lobbySettings.addEventListener('click', function () { openSettings(); });

  el.lobbyJoin.addEventListener('click', joinFromLobby);

  function joinFromLobby() {
    var name = el.lobbyName.value.trim();
    if (name && name !== meetingInfo.display_name) {
      meetingInfo.display_name = name;
      localStorage.setItem('cm_name', name);
    }
    el.lobby.hidden = true;
    el.meeting.hidden = false;
    startMeetingUI();
  }

  // ---------- in-meeting UI ----------
  function startMeetingUI() {
    renderLocalTile();
    el.detailCode.textContent = meetingInfo.meeting_code;
    el.detailLink.textContent = meetingInfo.join_url || ((CFG.baseUrl || '') + '/room.php?meeting=' + encodeURIComponent(meetingInfo.meeting_code));
    syncLocalToggles({ audioEnabled: RTC.micOn, videoEnabled: RTC.camOn });
    updateGrid();

    // Bind control bar.
    el.ctrlMic.addEventListener('click', function () {
      var on = RTC.toggleMic(RTC.micOn ? false : true);
      syncLocalToggles({ audioEnabled: on, videoEnabled: RTC.camOn });
      SIG.sendEvent('control', { field: 'audio_enabled', value: on ? 1 : 0 });
    });
    el.ctrlCam.addEventListener('click', function () {
      var on = RTC.toggleCam(RTC.camOn ? false : true);
      syncLocalToggles({ audioEnabled: RTC.micOn, videoEnabled: on });
      SIG.sendEvent('control', { field: 'video_enabled', value: on ? 1 : 0 });
    });
    el.ctrlShare.addEventListener('click', function () {
      if (RTC.isSharing) {
        RTC.stopShare().then(function () { SIG.sendEvent('screen_stop', {}); });
      } else {
        RTC.startShare().then(function () { SIG.sendEvent('screen_share', {}); });
      }
    });
    el.ctrlRec.addEventListener('click', toggleRecording);
    el.ctrlReactions.addEventListener('click', toggleReactionPicker);
    el.ctrlHand.addEventListener('click', toggleHand);
    el.ctrlCaptions.addEventListener('click', toggleCaptions);
    el.ctrlPanel.addEventListener('click', function () { togglePanel(); });
    el.ctrlSettings.addEventListener('click', openSettings);
    el.ctrlLeave.addEventListener('click', leaveMeeting);
    el.panelClose.addEventListener('click', function () { el.panel.classList.remove('open'); });
    el.copyLink.addEventListener('click', function () {
      copyText(el.detailLink.textContent, el.copyLink);
    });
    el.endConfirm.addEventListener('click', function () {
      SIG.endForEveryone().then(function () {
        onMeetingEnded('ended_by_host');
      }).catch(function (e) { toast(e.message || 'Could not end meeting.', 'err'); });
    });

    // Chat form.
    el.chatForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var text = el.chatText.value.trim();
      if (!text) return;
      SIG.sendChat(text).then(function () {
        el.chatText.value = '';
      }).catch(function (err) {
        if (err && err.success === false) toast(err.message || 'Message not sent.', 'warn');
      });
    });

    // Panel tabs.
    document.querySelectorAll('.cm-tab').forEach(function (t) {
      t.addEventListener('click', function () {
        document.querySelectorAll('.cm-tab').forEach(function (x) { x.classList.remove('active'); });
        t.classList.add('active');
        document.getElementById('cmPanelChat').hidden = t.dataset.panel !== 'chat';
        document.getElementById('cmPanelPeople').hidden = t.dataset.panel !== 'people';
        document.getElementById('cmPanelDetails').hidden = t.dataset.panel !== 'details';
      });
    });

    // Reaction picker buttons.
    el.reactionPicker.querySelectorAll('button').forEach(function (b) {
      b.addEventListener('click', function () {
        SIG.sendEvent('reaction', { emoji: b.dataset.emoji });
        el.reactionPicker.hidden = true;
      });
    });

    // Keyboard shortcuts.
    document.addEventListener('keydown', onKeydown);

    // Connection pill: default state.
    setConnPill('ok', 'Connected');
  }

  // ---------- tiles ----------
  function renderLocalTile() {
    if (localTile) return;
    var t = document.createElement('div');
    t.className = 'cm-tile cam-off';
    t.dataset.pid = 'me';
    t.innerHTML =
      '<div class="cm-tile-avatar-wrap"></div>' +
      '<video class="cm-video local" autoplay playsinline muted></video>' +
      '<span class="cm-tile-name"></span>' +
      '<div class="cm-tile-badges">' +
        '<span class="cm-badge host" title="Host"></span>' +
        '<span class="cm-badge hand" title="Hand raised">✋</span>' +
      '</div>';
    el.grid.appendChild(t);
    localTile = t;
    var video = t.querySelector('video');
    video.srcObject = RTC.localStream || new MediaStream();
    t.querySelector('.cm-tile-name').textContent = meetingInfo.display_name || 'You';
    updateLocalTileState();
  }

  function updateLocalTileState() {
    if (!localTile) return;
    var video = localTile.querySelector('video');
    if (RTC.localStream) video.srcObject = RTC.localStream;
    localTile.classList.toggle('cam-off', !RTC.camOn || !RTC.localStream || !RTC.localStream.getVideoTracks().length);
    var badges = localTile.querySelectorAll('.cm-badge');
    // host badge: show only if I'm the host
    localTile.querySelectorAll('.cm-badge.host').forEach(function (b) {
      b.style.display = (meetingInfo.role === 'host') ? 'flex' : 'none';
    });
    localTile.querySelectorAll('.cm-badge.hand').forEach(function (b) {
      b.style.display = 'none'; // raised hand reflected separately
    });
  }

  function onRemoteStream(pid, stream, name) {
    var existing = document.querySelector('.cm-tile[data-pid="' + pid + '"]');
    var tile = existing || createTile(pid, name);
    var video = tile.querySelector('video');
    video.srcObject = stream;
    video.classList.remove('hidden');
    // If a screen share track, mark it.
    var screenTrack = stream.getVideoTracks().filter(function (t) { return t.label && /screen|desktop/i.test(t.label); })[0];
    if (screenTrack || RTC.isSharing) {
      tile.classList.add('sharing');
    }
  }

  function createTile(pid, name) {
    var t = document.createElement('div');
    t.className = 'cm-tile cam-off';
    t.dataset.pid = pid;
    t.innerHTML =
      '<div class="cm-avatar-initial"></div>' +
      '<video class="cm-video" autoplay playsinline></video>' +
      '<span class="cm-tile-name">' + esc(name || '…') + '</span>' +
      '<div class="cm-tile-badges">' +
        '<span class="cm-badge muted" title="Muted">🔇</span>' +
        '<span class="cm-badge hand" title="Hand raised" style="display:none">✋</span>' +
      '</div>';
    el.grid.appendChild(t);
    return t;
  }

  function onRemoteGone(pid) {
    var t = document.querySelector('.cm-tile[data-pid="' + pid + '"]');
    if (t) t.remove();
    updateGrid();
  }

  function onPeerState(pid, state) {
    // Reflect in the tile badge when state becomes bad.
    var t = document.querySelector('.cm-tile[data-pid="' + pid + '"]');
    if (!t) return;
    if (state === 'disconnected' || state === 'failed') {
      setConnPill('warn', 'Connection unstable — attempting recovery…');
    } else if (state === 'connected' || state === 'completed') {
      setConnPill('ok', 'Connected');
    }
  }

  function onActiveSpeaker(pid) {
    activeSpeakerId = pid;
    document.querySelectorAll('.cm-tile').forEach(function (t) {
      var isSpeaker = (pid === null || pid === undefined) ? false : (String(t.dataset.pid) === String(pid));
      t.classList.toggle('active-speaker', isSpeaker);
    });
    if (pid === null) document.querySelectorAll('.cm-tile').forEach(function (t) { t.classList.remove('active-speaker'); });
  }

  function onShareState(sharing) {
    if (sharing) {
      // Mark the local tile as presenter.
      if (localTile) {
        localTile.classList.add('sharing');
        var tag = localTile.querySelector('.cm-tile-screen-tag');
        if (!tag) {
          tag = document.createElement('span');
          tag.className = 'cm-tile-screen-tag';
          tag.textContent = 'Sharing';
          localTile.appendChild(tag);
        }
      }
    } else {
      if (localTile) {
        localTile.classList.remove('sharing');
        var tag = localTile.querySelector('.cm-tile-screen-tag');
        if (tag) tag.remove();
      }
    }
    updateGrid();
  }

  function syncLocalToggles(state) {
    el.ctrlMic.classList.toggle('off', !state.audioEnabled);
    el.ctrlMic.setAttribute('aria-pressed', String(!!state.audioEnabled));
    el.lobbyMic.classList.toggle('off', !state.audioEnabled);
    el.lobbyMic.setAttribute('aria-pressed', String(!!state.audioEnabled));

    el.ctrlCam.classList.toggle('off', !state.videoEnabled);
    el.ctrlCam.setAttribute('aria-pressed', String(!!state.videoEnabled));
    el.lobbyCam.classList.toggle('off', !state.videoEnabled);
    el.lobbyCam.setAttribute('aria-pressed', String(!!state.videoEnabled));

    if (localTile) updateLocalTileState();
  }

  function onLocalLevel(rms) {
    var pct = Math.min(100, Math.round(rms * 300));
    el.micMeterFill.style.width = pct + '%';
    var set = document.getElementById('cmSetMicMeter');
    if (set) set.style.width = pct + '%';
  }

  function updateGrid() {
    var tiles = el.grid.querySelectorAll('.cm-tile');
    var n = tiles.length;
    el.grid.classList.remove('grid-1', 'grid-2', 'grid-3', 'grid-4', 'grid-5', 'grid-many');
    if (n <= 1) el.grid.classList.add('grid-1');
    else if (n === 2) el.grid.classList.add('grid-2');
    else if (n <= 4) el.grid.classList.add('grid-' + n);
    else if (n <= 6) el.grid.classList.add('grid-5');
    else el.grid.classList.add('grid-many');
  }

  // ---------- participant updates ----------
  function onParticipantUpdates(parts, meta) {
    participantCache = parts || [];
    // Ensure tiles for every remote participant.
    (parts || []).forEach(function (p) {
      if (String(p.id) === String(myId)) return;
      var existing = document.querySelector('.cm-tile[data-pid="' + p.id + '"]');
      if (!existing) {
        RTC.ensurePeer(p.id, p.display_name);
      } else {
        // Update state (mute, hand, share).
        existing.querySelector('.cm-tile-name').textContent = p.display_name || '…';
        var muted = existing.querySelector('.cm-badge.muted');
        if (muted) muted.style.display = p.audio_enabled ? 'none' : 'flex';
        var hand = existing.querySelector('.cm-badge.hand');
        if (hand) hand.style.display = p.hand_raised ? 'flex' : 'none';
      }
    });
    // Remove tiles for participants who left.
    var liveIds = {};
    (parts || []).forEach(function (p) { liveIds[String(p.id)] = true; });
    el.grid.querySelectorAll('.cm-tile').forEach(function (t) {
      var pid = t.dataset.pid;
      if (pid === 'me') return;
      if (!liveIds[pid]) {
        // Only remove if it was a real remote tile.
        RTC.removePeer(pid);
        t.remove();
      }
    });
    updateGrid();
    renderPeopleList(parts);
  }

  function renderPeopleList(parts) {
    el.peopleList.innerHTML = '';
    (parts || []).forEach(function (p) {
      var li = document.createElement('li');
      li.className = 'cm-person';
      var isMe = String(p.id) === String(myId);
      li.innerHTML =
        '<span class="cm-person-avatar">' + esc((p.display_name || '?').charAt(0).toUpperCase()) + '</span>' +
        '<span class="cm-person-name">' +
          esc(p.display_name || 'Guest') +
          (p.role === 'host' ? ' <span class="tag">Host</span>' : '') +
          (isMe ? ' <span class="tag">(you)</span>' : '') +
        '</span>' +
        '<span class="cm-person-state">' +
          '<span title="' + (p.audio_enabled ? 'Unmuted' : 'Muted') + '">' + (p.audio_enabled ? '🎙' : '🔇') + '</span>' +
          '<span title="' + (p.video_enabled ? 'Camera on' : 'Camera off') + '">' + (p.video_enabled ? '📷' : '—') + '</span>' +
          (p.hand_raised ? '<span title="Hand raised">✋</span>' : '') +
        '</span>' +
        ((p.role === 'host' && !isMe) ? '<button class="cm-people-remove" data-pid="' + p.id + '" title="Remove">✕</button>' : '');
      el.peopleList.appendChild(li);
    });
    el.peopleList.querySelectorAll('.cm-people-remove').forEach(function (b) {
      b.addEventListener('click', function () {
        if (confirm('Remove this participant?')) {
          SIG.removeParticipant(parseInt(b.dataset.pid, 10)).then(function () {
            toast('Participant removed.', 'ok');
          }).catch(function (e) { toast((e && e.message) || 'Could not remove.', 'err'); });
        }
      });
    });
  }

  // ---------- reactions ----------
  function toggleReactionPicker() {
    var open = el.reactionPicker.hasAttribute('hidden');
    if (open) el.reactionPicker.removeAttribute('hidden');
    else el.reactionPicker.setAttribute('hidden', '');
  }
  function spawnReaction(emoji) {
    var r = document.createElement('div');
    r.className = 'cm-reaction';
    r.textContent = emoji;
    r.style.left = (20 + Math.random() * 60) + '%';
    r.style.bottom = '6rem';
    el.reactionLayer.appendChild(r);
    setTimeout(function () { r.remove(); }, 2400);
  }

  // ---------- hand ----------
  var handRaised = false;
  function toggleHand() {
    handRaised = !handRaised;
    el.ctrlHand.setAttribute('aria-pressed', String(handRaised));
    el.ctrlHand.classList.toggle('active', handRaised);
    SIG.sendEvent(handRaised ? 'hand_raise' : 'hand_lower', {});
    if (localTile) {
      var b = localTile.querySelector('.cm-badge.hand');
      if (b) b.style.display = handRaised ? 'flex' : 'none';
    }
  }

  // ---------- captions (Web Speech API, best-effort) ----------
  function toggleCaptions() {
    captionsEnabled = !captionsEnabled;
    el.captions.hidden = !captionsEnabled;
    el.ctrlCaptions.setAttribute('aria-pressed', String(captionsEnabled));
    el.ctrlCaptions.classList.toggle('active', captionsEnabled);
    if (captionsEnabled && !global.SpeechRecognition && !global.webkitSpeechRecognition) {
      el.captions.textContent = 'Live captions are not supported in this browser. Your local speech will be transcribed where available; remote participants’ speech is not captioned in the mesh prototype.';
      toast('Captions are limited in this browser.', 'warn');
    } else if (captionsEnabled) {
      startSpeechRecognition();
    } else {
      stopSpeechRecognition();
    }
  }

  function startSpeechRecognition() {
    var SR = global.SpeechRecognition || global.webkitSpeechRecognition;
    if (!SR) { stopSpeechRecognition(); return; }
    try {
      speechRec = new SR();
      speechRec.continuous = true;
      speechRec.interimResults = true;
      speechRec.lang = (navigator.language || 'en-US').slice(0, 5);
      speechRec.onresult = function (e) {
        var finalText = '', interimText = '';
        for (var i = e.resultIndex; i < e.results.length; i++) {
          var t = e.results[i][0].transcript;
          if (e.results[i].isFinal) finalText += t; else interimText += t;
        }
        var line = (finalText || interimText).trim();
        if (line) el.captions.textContent = line;
      };
      speechRec.onend = function () { if (captionsEnabled && RTC.micOn) { try { speechRec.start(); } catch (e) {} } };
      speechRec.start();
    } catch (e) {
      el.captions.textContent = 'Speech recognition failed to start: ' + e.message;
    }
  }
  function stopSpeechRecognition() {
    if (speechRec) { try { speechRec.stop(); } catch (e) {} speechRec = null; }
    el.captions.textContent = '';
  }

  // ---------- recording (MediaRecorder, local only) ----------
  function toggleRecording() {
    if (!global.MediaRecorder) { toast('Recording is not supported in this browser.', 'warn'); return; }
    if (recording) {
      stopRecording();
      return;
    }
    // Combine local + all remote streams into one MediaStream for the recorder.
    var allTracks = [];
    if (RTC.localStream) allTracks = allTracks.concat(RTC.localStream.getTracks());
    Object.keys(RTC.peers).forEach(function (pid) {
      var p = RTC.peers[pid];
      if (p && p.stream) allTracks = allTracks.concat(p.stream.getTracks());
    });
    var mixed = new MediaStream(allTracks);
    if (!allTracks.length) { toast('No audio/video to record yet.', 'warn'); return; }

    var mime = pickMime();
    var rec = new MediaRecorder(mixed, mime ? { mimeType: mime } : undefined);
    recChunks = [];
    rec.ondataavailable = function (e) { if (e.data && e.data.size) recChunks.push(e.data); };
    rec.onstop = function () {
      var blob = new Blob(recChunks, { type: rec.mimeType || 'video/webm' });
      recChunks = [];
      var url = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = url;
      a.download = 'connectmeet-recording-' + new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-') + '.webm';
      a.click();
      setTimeout(function () { URL.revokeObjectURL(url); }, 3000);
      toast('Recording saved to your downloads.', 'ok');
      recSeconds = 0;
      el.recTime.textContent = '00:00';
    };
    recording = rec;
    rec.start();
    el.recIndicator.hidden = false;
    el.ctrlRec.classList.add('active');
    SIG.sendEvent('control', { recording: 1 });
    var timer = setInterval(function () {
      recSeconds++;
      var m = String(Math.floor(recSeconds / 60)).padStart(2, '0');
      var s = String(recSeconds % 60).padStart(2, '0');
      el.recTime.textContent = m + ':' + s;
    }, 1000);
    recording._timer = timer;
    toast('Recording locally (your browser only). Other participants are not server-recorded.', 'ok');
  }
  function stopRecording() {
    if (!recording) return;
    if (recording._timer) clearInterval(recording._timer);
    recording.stop();
    recording = null;
    el.recIndicator.hidden = true;
    el.ctrlRec.classList.remove('active');
  }
  function pickMime() {
    var candidates = ['video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm', 'video/mp4'];
    for (var i = 0; i < candidates.length; i++) {
      if (global.MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(candidates[i])) return candidates[i];
    }
    return '';
  }

  // ---------- connection pill ----------
  function setConnPill(kind, text) {
    el.connPill.classList.remove('warn', 'err');
    if (kind === 'warn') el.connPill.classList.add('warn');
    if (kind === 'err') el.connPill.classList.add('err');
    el.connPill.textContent = text;
    el.detailStatus.textContent = text;
  }

  // ---------- chat ----------
  function onChatMessages(msgs) {
    msgs.forEach(function (m) {
      if (m.id <= SIG.lastChatId) return;
      addChatMessage(m);
    });
  }
  function addChatMessage(m) {
    var div = document.createElement('div');
    var isMine = m.sender && m.sender === (meetingInfo.display_name || '');
    div.className = 'cm-msg' + (isMine ? ' mine' : '');
    div.innerHTML =
      '<div class="cm-msg-head">' +
        '<span class="cm-msg-name">' + esc(m.sender || 'System') + '</span>' +
        (m.role === 'host' ? ' <span class="cm-msg-role">host</span>' : '') +
        ' <span class="cm-msg-time">' + esc(m.time || '') + '</span>' +
      '</div>' +
      '<div class="cm-msg-body"></div>';
    div.querySelector('.cm-msg-body').textContent = m.content; // safe: textContent
    el.chatList.appendChild(div);
    el.chatList.scrollTop = el.chatList.scrollHeight;
  }

  // ---------- system events (reactions / hand / screen / captions / control) ----------
  global.CMEVT = {
    onSystemEvent: function (ev) {
      switch (ev.event) {
        case 'reaction':
          spawnReaction((ev.payload && ev.payload.emoji) || '👍');
          break;
        case 'hand_raise':
          var t = document.querySelector('.cm-tile[data-pid="' + ev.from + '"]');
          if (t) { var h = t.querySelector('.cm-badge.hand'); if (h) h.style.display = 'flex'; }
          break;
        case 'hand_lower':
          var t2 = document.querySelector('.cm-tile[data-pid="' + ev.from + '"]');
          if (t2) { var h2 = t2.querySelector('.cm-badge.hand'); if (h2) h2.style.display = 'none'; }
          break;
        case 'screen_share':
          // Remote presenter: broadcast a control event so others show the
          // "Sharing" tag on that person's tile. The video itself is already
          // flowing over their peer connection (they replaced their local track).
          var t3 = document.querySelector('.cm-tile[data-pid="' + ev.from + '"]');
          if (t3) {
            var tag = t3.querySelector('.cm-tile-screen-tag');
            if (!tag) {
              tag = document.createElement('span');
              tag.className = 'cm-tile-screen-tag';
              tag.textContent = 'Sharing';
              t3.appendChild(tag);
            }
          }
          break;
        case 'screen_stop':
          var t4 = document.querySelector('.cm-tile[data-pid="' + ev.from + '"]');
          if (t4) { var tag2 = t4.querySelector('.cm-tile-screen-tag'); if (tag2) tag2.remove(); }
          break;
        case 'control':
          // audio/video state already synced via heartbeat/poll; ignore here.
          break;
      }
    }
  };

  // ---------- leave / ended ----------
  function leaveMeeting() {
    if (confirm('Leave the meeting?')) {
      stopRecording();
      SIG.leave().then(function () {
        location.replace((CFG.baseUrl || '') + '/index.php');
      }).catch(function () { location.replace((CFG.baseUrl || '') + '/index.php'); });
    }
  }

  function onMeetingEnded(reason) {
    stopRecording();
    var msg = {
      'ended_by_host': 'The host ended the meeting.',
      'credential_expired': 'Your session expired. Please rejoin.',
      'expired': 'This meeting has expired.',
      'locked': 'This meeting is locked.'
    }[reason] || 'Meeting ended.';
    el.statusRibbon.hidden = false;
    el.statusRibbon.textContent = msg + ' Returning to home…';
    setConnPill('warn', 'Ending');
    SIG.leave(); // stop timers, teardown media
    setTimeout(function () { location.replace((CFG.baseUrl || '') + '/index.php'); }, 3000);
  }

  // ---------- settings (delegated to settings.js) ----------
  function openSettings() {
    global.CM_SETTINGS && CM_SETTINGS.open ? CM_SETTINGS.open() : document.getElementById('cmSettingsModal').removeAttribute('hidden');
  }

  // NOTE: device selection, speaker test, captions pref, and name persistence
  // are handled in assets/js/settings.js (window.CM_SETTINGS). This meeting
  // controller only opens the modal.

  // ---------- keyboard ----------
  function onKeydown(e) {
    if (el.meeting.hidden || e.target && e.target.matches && e.target.matches('input, textarea, select')) return;
    var k = e.key.toLowerCase();
    if (k === 'a') { el.ctrlMic.click(); }
    else if (k === 'v') { el.ctrlCam.click(); }
    else if (k === 'h') { toggleHand(); }
    else if (k === 't') { toggleCaptions(); }
    else if (k === 'escape') {
      if (!el.reactionPicker.hasAttribute('hidden')) el.reactionPicker.setAttribute('hidden', '');
      else if (el.panel.classList.contains('open')) el.panel.classList.remove('open');
    }
  }

  function togglePanel() {
    var open = el.panel.classList.toggle('open');
    el.panel.hidden = !open;
  }

  // ---------- misc ----------
  function toggleMic(state) { syncLocalToggles({ audioEnabled: state, videoEnabled: RTC.camOn }); }
  function toggleCam(state) { syncLocalToggles({ audioEnabled: RTC.micOn, videoEnabled: state }); }
  function updateLobbyMediaUI() {
    syncLocalToggles({ audioEnabled: RTC.micOn, videoEnabled: RTC.camOn });
    el.lobbyPreviewWrap.classList.toggle('cam-off', !RTC.camOn || !RTC.localStream || !RTC.localStream.getVideoTracks().length);
    el.lobbyAvatar.hidden = !el.lobbyPreviewWrap.classList.contains('cam-off');
  }
  function toast(msg, kind) {
    var t = document.createElement('div');
    t.className = 'cm-toast' + (kind ? ' ' + kind : '');
    t.textContent = msg;
    el.toasts.appendChild(t);
    setTimeout(function () { t.remove(); }, 4000);
  }
  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }
  function copyText(text, btn) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () { flashCopied(btn); });
    } else {
      var ta = document.createElement('textarea');
      ta.value = text; document.body.appendChild(ta); ta.select();
      document.execCommand('copy'); ta.remove(); flashCopied(btn);
    }
  }
  function flashCopied(btn) {
    if (!btn) return;
    var orig = btn.textContent;
    btn.textContent = 'Copied';
    setTimeout(function () { btn.textContent = orig; }, 1500);
  }

  boot();
})(window);
